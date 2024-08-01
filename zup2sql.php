<?php
    require 'vendor/autoload.php';

    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $db_host = $_ENV['DB_HOST'];
    $db_port = $_ENV['DB_PORT'];
    $db_name = $_ENV['DB_NAME'];
    $db_user = $_ENV['DB_USER'];
    $db_password = $_ENV['DB_PASSWORD'];
    define('DB_TYPE', $_ENV['DB_TYPE']);

    /**
     * @param $message
     * @return void
     */
    function logMessage($message): void
    {
        $timestamp = date('Ymd H:i:s');
        $logFile = 'process_log.txt';
        file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
        echo "[$timestamp] $message\n";
    }

    /**
     * @return bool|array
     */
    function getZupFiles(): bool|array
    {
        $files = glob('*.ZUP');
        $processedFiles = file_exists('processed_files.txt') ? file('processed_files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        return array_diff($files, $processedFiles);
    }

    /**
     * @param $tag
     * @return string
     */
    function processCreateField($tag): string
    {
        $table = (string) $tag['NomeTabela'];
        $field = (string) $tag['NomeCampo'];
        $type = (string) $tag['TipoDado'];
        $size = (string) $tag['Tamanho'];
        $precision = (string) $tag['Precisao'];
        $nullable = (string) $tag['Null'] === 'S' ? 'NULL' : 'NOT NULL';
        $default = (string) $tag['Default'];
        $comment = (string) $tag['Comentario'];

        $sql = "ALTER TABLE $table ADD COLUMN $field $type";

        if ($size) {
            $sql .= "($size";
            if ($precision) {
                $sql .= ", $precision";
            }
            $sql .= ")";
        }

        $sql .= " $nullable";

        if ($default !== '') {
            $sql .= " DEFAULT '$default'";
        }

        if ($comment !== '') {
            $sql .= "; COMMENT ON COLUMN $table.$field IS '$comment'";
        }

        return $sql . ";";
    }

    /**
     * @param $tag
     * @return string
     */
    function processCreateIndex($tag): string
    {
        $indexName = (string) $tag['NomeIndice'];
        $tableName = (string) $tag['NomeTabela'];
        $unique = (string) $tag['Unique'] === 'S' ? 'UNIQUE' : '';
        $fields = [];

        foreach ($tag->Campo as $field) {
            $fields[] = (string) $field;
        }

        $fieldsList = implode(', ', $fields);

        return "CREATE $unique INDEX $indexName ON $tableName ($fieldsList);";
    }

    if ($argc === 2) {
        $filename = $argv[1];
    } else {
        $files = getZupFiles();
        if (empty($files)) {
            fprintf(STDERR, "Nenhum arquivo .ZUP encontrado para processar.\n");
            exit(1);
        }
        $filename = reset($files);
    }

    if (!file_exists($filename)) {
        fprintf(STDERR, "Arquivo não encontrado: %s\n", $filename);
        exit(1);
    }

    file_put_contents('processed_files.txt', $filename . PHP_EOL, FILE_APPEND);

    $content = file_get_contents($filename);

    if (!simplexml_load_string($content)) {
        fprintf(STDERR, "O arquivo não é um XML válido: %s\n", $filename);
        exit(1);
    }

    $xml = simplexml_load_string($content);

    $sql_tags = $xml->xpath('//SQL');
    $create_field_tags = $xml->xpath('//CriarCampo');
    $create_index_tags = $xml->xpath('//CriarIndice');

    if (!$sql_tags && !$create_field_tags && !$create_index_tags) {
        fprintf(STDERR, "Nenhuma tag <SQL>, <CriarCampo> ou <CriarIndice> encontrada no arquivo: %s\n", $filename);
        exit(1);
    }

    $output_filename = pathinfo($filename, PATHINFO_FILENAME) . '.sql';

    $output_file = fopen($output_filename, 'w');
    if (!$output_file) {
        fprintf(STDERR, "Não foi possível criar o arquivo de saída: %s\n", $output_filename);
        exit(1);
    }

    $tables_to_drop = [];

    foreach ($create_field_tags as $create_field) {
        $table_name = (string) $create_field['NomeTabela'];
        if (!in_array($table_name, $tables_to_drop)) {
            $tables_to_drop[] = $table_name;
        }
        $sql_command = processCreateField($create_field);
        fwrite($output_file, $sql_command . "\n");
    }

    foreach ($create_index_tags as $create_index) {
        $table_name = (string) $create_index['NomeTabela'];
        if (!in_array($table_name, $tables_to_drop)) {
            $tables_to_drop[] = $table_name;
        }
        $sql_command = processCreateIndex($create_index);
        fwrite($output_file, $sql_command . "\n");
    }

    foreach ($sql_tags as $sql) {
        $specific_sql = $sql->{DB_TYPE};
        if ($specific_sql) {
            fwrite($output_file, $specific_sql . "\n");
        }
    }

    fclose($output_file);

    logMessage("O conteúdo das tags <SQL>, <CriarCampo> e <CriarIndice> foi salvo em $output_filename");

    $conn = pg_connect("host=$db_host port=$db_port dbname=$db_name user=$db_user password=$db_password");

    if (!$conn) {
        fprintf(STDERR, "Erro ao conectar ao banco de dados.\n");
        exit(1);
    }

    logMessage("Conexão com o banco de dados estabelecida.");

    $sql_commands = file($output_filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    pg_query($conn, 'BEGIN');

    $processes = [];
    $max_parallel_processes = 4;
    $pipes = [];

    try {
        foreach ($tables_to_drop as $table) {
            $drop_command = "DROP TABLE IF EXISTS $table CASCADE;";
            logMessage("Executando: $drop_command");
            pg_query($conn, $drop_command);
        }

        foreach ($sql_commands as $command) {
            while (count($processes) >= $max_parallel_processes) {
                foreach ($processes as $key => $process) {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        $stdout = stream_get_contents($pipes[$key][1]);
                        $stderr = stream_get_contents($pipes[$key][2]);
                        logMessage("Saída: $stdout");
                        logMessage("Erro: $stderr");
                        fclose($pipes[$key][1]);
                        fclose($pipes[$key][2]);
                        proc_close($process);
                        unset($processes[$key]);
                        unset($pipes[$key]);
                    }
                }
                usleep(100000);
            }

            $descriptorSpec = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ];

            $process = proc_open("psql -h $db_host -p $db_port -U $db_user -d $db_name -c \"$command\"", $descriptorSpec, $pipes[$key]);

            if (is_resource($process)) {
                fwrite($pipes[$key][0], $db_password . "\n");
                fclose($pipes[$key][0]);
                $processes[$key] = $process;
            } else {
                throw new Exception("Erro ao iniciar processo para comando: $command\n");
            }
        }

        foreach ($processes as $key => $process) {
            $stdout = stream_get_contents($pipes[$key][1]);
            $stderr = stream_get_contents($pipes[$key][2]);
            logMessage("Saída: $stdout");
            logMessage("Erro: $stderr");
            fclose($pipes[$key][1]);
            fclose($pipes[$key][2]);
            proc_close($process);
        }

        pg_query($conn, 'COMMIT');
        logMessage("Todos os comandos SQL foram executados com sucesso.");
    } catch (Exception $e) {
        pg_query($conn, 'ROLLBACK');
        logMessage("Erro encontrado: " . $e->getMessage());
        logMessage("A transação foi revertida.");
    }

    pg_close($conn);
