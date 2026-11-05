#!/bin/bash
service mysql start
until mysqladmin ping --silent; do sleep 1; done
mysql -e "CREATE DATABASE IF NOT EXISTS aidetect;
CREATE USER IF NOT EXISTS 'app'@'localhost' IDENTIFIED BY 'apppass';
CREATE USER IF NOT EXISTS 'app'@'127.0.0.1' IDENTIFIED BY 'apppass';
GRANT ALL ON aidetect.* TO 'app'@'localhost';
GRANT ALL ON aidetect.* TO 'app'@'127.0.0.1';
FLUSH PRIVILEGES;"
mysql aidetect < /docker-init/init.sql
exec apache2ctl -D FOREGROUND
