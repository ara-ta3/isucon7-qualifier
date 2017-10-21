nginx_log=/tmp/nginx-access.log

alp:
	sudo cat $(nginx_log)|alp --sum --aggregates "/icons/.+"

alp/less:
	sudo cat $(nginx_log)|alp --sum --aggregates "/icons/.+"|less -Ss

restart/php:
	sudo systemctl restart isubata.php.service

clean/nginx:
	sudo rm -f $(nginx_log)
	$(MAKE) restart/nginx

restart/nginx:
	sudo systemctl restart nginx.service
