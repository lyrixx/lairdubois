#!/bin/bash

# NGINX
rm -rf /etc/nginx/sites-enabled/www.lairdubois.fr.conf
ln -s /etc/nginx/sites-available/www.lairdubois.fr-maintenance.conf /etc/nginx/sites-enabled/www.lairdubois.fr.conf
service nginx restart
service php8.5-fpm restart

# SERVICES
# The scheduler first: it puts no more runs in the queue. The cron consumer finishes the one it runs (TimeoutStopSec)
service ladb_scheduler stop
service ladb_consumer_cron stop
service ladb_consumer_mailer stop
service ladb_consumer_view stop
service ladb_consumer_webpush stop
