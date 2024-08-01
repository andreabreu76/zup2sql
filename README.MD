# Zup2Sql

Este projeto processa arquivos `.ZUP`, extrai comandos SQL específicos e os executa em um banco de dados PostgreSQL. O script também suporta paralelismo para executar comandos SQL simultaneamente.

## Requisitos

- PHP 7.4 ou superior
- Composer
- PostgreSQL
- Biblioteca `vlucas/phpdotenv`

## Instalação

### 1. Instale o Composer

Se você ainda não tiver o Composer instalado, pode instalá-lo seguindo as instruções no site oficial: [Composer Installation](https://getcomposer.org/download/).

### 2. Clone o repositório

Clone o repositório para o seu diretório local:

```sh
git clone <URL_DO_REPOSITORIO>
cd <NOME_DO_DIRETORIO>
```

### 3. Inicie um novo projeto com Composer

Navegue até o diretório do seu projeto e inicie um novo projeto com Composer:

```sh
composer init
```

### 4. Instale o `vlucas/phpdotenv`

Execute o seguinte comando para instalar o pacote `vlucas/phpdotenv`:

```sh
composer require vlucas/phpdotenv
```

### 5. Crie um arquivo `.env`

Crie um arquivo `.env` no diretório raiz do seu projeto com as seguintes configurações:

```dotenv
DB_HOST=localhost
DB_PORT=5432
DB_NAME=seu_banco
DB_USER=seu_usuario
DB_PASSWORD=sua_senha
DB_TYPE=POSTGRESQL
```

### 6. Estrutura do Projeto

Após a instalação, sua estrutura de diretórios do projeto deve se parecer com isso:

```
/seu_projeto
|-- /vendor
|-- composer.json
|-- composer.lock
|-- .env
|-- zup2sql.php
```

### 7. Executar o Script

> Antes de executar o script, você precisa ter baixado os arquivos .ZUP e também a base pertiente ao banco de dados PostgreSQL.

Você pode agora executar o script usando o terminal, fornecendo o caminho do arquivo `.ZUP` ou deixando o script encontrar automaticamente o próximo arquivo não processado:

Para especificar um arquivo:

```sh
php zup2sql.php /mnt/data/ZeusRetail_2_14_154_0.ZUP
```

Para processar automaticamente o próximo arquivo não processado no diretório raiz:

```sh
php zup2sql.php
```

## Funcionalidades

- **Extrai comandos SQL de arquivos `.ZUP`**: O script lê os arquivos `.ZUP`, interpreta como XML e extrai o conteúdo das tags `<SQL>`, `<CriarCampo>` e `<CriarIndice>` específicas para o banco de dados definido.
- **Suporta paralelismo**: Executa comandos SQL em paralelo para melhorar o desempenho.
- **Registra logs**: Gera logs detalhados das operações, incluindo saídas de erro, em `process_log.txt`.
- **Executa comandos SQL no PostgreSQL**: Conecta-se ao banco de dados PostgreSQL e executa os comandos SQL extraídos em uma transação, fazendo `commit` ou `rollback` conforme necessário.

## Estrutura do Script

- **processCreateField**: Função que processa a tag `<CriarCampo>` e gera comandos SQL para adicionar campos a tabelas.
- **processCreateIndex**: Função que processa a tag `<CriarIndice>` e gera comandos SQL para criar índices em tabelas.
- **logMessage**: Função que registra mensagens de log com timestamp.
- **getZupFiles**: Função que obtém a lista de arquivos `.ZUP` no diretório raiz que ainda não foram processados.
- **Execução paralela**: Utiliza `proc_open` para executar comandos SQL em paralelo e captura a saída padrão e de erro.

## Contribuição

Sinta-se à vontade para contribuir com este projeto. Você pode abrir issues para relatar bugs ou sugerir melhorias, e enviar pull requests para novas funcionalidades ou correções.

## Licença

Este projeto está licenciado sob a licença MIT. Veja o arquivo LICENSE para mais detalhes.


