FROM php:8.1-apache

# 필수 PHP 확장
RUN docker-php-ext-install pdo pdo_mysql mysqli mbstring

# mod_rewrite 활성화
RUN a2enmod rewrite

# 커스텀 VirtualHost (document root → /public)
COPY vhost.conf /etc/apache2/sites-available/000-default.conf

# 세션 저장소 퍼미션
RUN chmod 777 /tmp
