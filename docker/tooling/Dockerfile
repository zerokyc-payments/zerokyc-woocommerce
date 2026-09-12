FROM php:8.1-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends subversion default-mysql-client unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install mysqli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && composer global require \
        phpunit/phpunit:^9.6 \
        yoast/phpunit-polyfills:^2.0 \
        wp-coding-standards/wpcs:^3.1 \
        dealerdirect/phpcodesniffer-composer-installer:^1.0 \
    && ln -s /root/.composer/vendor/bin/phpunit /usr/local/bin/phpunit \
    && ln -s /root/.composer/vendor/bin/phpcs /usr/local/bin/phpcs \
    && ln -s /root/.composer/vendor/bin/phpcbf /usr/local/bin/phpcbf

ENV WP_TESTS_PHPUNIT_POLYFILLS_PATH=/root/.composer/vendor/yoast/phpunit-polyfills

# WordPress + test library are exported via SVN at runtime (entrypoint);
# WooCommerce for integration tests is cached in the image.
RUN mkdir -p /opt/plugins \
    && curl -sSL https://downloads.wordpress.org/plugin/woocommerce.zip -o /tmp/woocommerce.zip \
    && unzip -q /tmp/woocommerce.zip -d /opt/plugins \
    && rm /tmp/woocommerce.zip

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /app

ENTRYPOINT [ "/usr/local/bin/entrypoint.sh" ]
CMD [ "phpunit" ]
