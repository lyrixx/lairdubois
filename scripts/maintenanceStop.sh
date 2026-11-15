#!/bin/bash

# NGINX
rm -rf /etc/nginx/sites-enabled/www.lairdubois.fr.conf
ln -s /etc/nginx/sites-available/www.lairdubois.fr.conf /etc/nginx/sites-enabled/www.lairdubois.fr.conf
# A vhost that nginx refuses: back to the maintenance page, the site stays closed
if ! nginx -t; then
    rm -rf /etc/nginx/sites-enabled/www.lairdubois.fr.conf
    ln -s /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf /etc/nginx/sites-enabled/www.lairdubois.fr.conf
    echo "nginx -t failed: the site stays in maintenance." >&2
    exit 1
fi
service php8.5-fpm restart
service nginx restart

# SERVICES
service ladb_consumer_mailer start
service ladb_consumer_view start
service ladb_consumer_webpush start
service ladb_consumer_cron start
service ladb_scheduler start
