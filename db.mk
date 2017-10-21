slow_query=/var/log/mysql/mysql-slow.log

slow/ptquery:
	sudo pt-query-digest $(slow_query)

clean/slow:
	sudo rm -f $(slow_query)
	sudo systemctl restart mysql.service

mysql:
	mysql -uisucon -pisucon isubata
