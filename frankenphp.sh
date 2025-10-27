#! /bin/bash
# this script is my local example, how to start the frankenphp server
APP_ENV=development 
APP_DEBUG=true 
DB_HOST=localhost
DB_NAME=synaplan
DB_USER=synaplan
DB_PASSWORD=synaplan
OLLAMA_SERVER=localhost:11434  
/usr/local/bin/frankenphp php-server --listen 0.0.0.0:81 --root /wwwroot/synaplan/public/
