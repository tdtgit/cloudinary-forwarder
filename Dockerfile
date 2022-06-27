FROM alpine:3.14

RUN apk add \
    php8 php8-fpm php8-curl \
    nginx

COPY docker/site.conf /etc/nginx/http.d/default.conf
RUN echo -ne "env[CLOUDINARY_CLOUD_NAME] = \$CLOUDINARY_CLOUD_NAME\nenv[CLOUDINARY_CLOUD_MAPPING] = \$CLOUDINARY_CLOUD_MAPPING\n" >> /etc/php8/php-fpm.d/www.conf

COPY index.php /var/www/html/index.php

ADD docker/entrypoint.sh /entrypoint.sh

ENTRYPOINT ["sh", "/entrypoint.sh"]