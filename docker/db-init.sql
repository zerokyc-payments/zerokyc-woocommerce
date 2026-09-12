-- Second database for the dev site (tests use wordpress_test and rebuild it).
CREATE DATABASE IF NOT EXISTS `wordpress_site`;
GRANT ALL PRIVILEGES ON `wordpress_site`.* TO 'wp'@'%';
FLUSH PRIVILEGES;
