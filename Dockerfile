FROM alpine:3.14

RUN apk add \
    php8 php8-fpm php8-curl \
    nginx

COPY docker/site.conf /etc/nginx/http.d/default.conf

COPY index.php /var/www/html/index.php

ADD docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]