deploy:
	git pull origin master

server: js css fonts favicon.ico
	php -S localhost:8080 -t .

js:
	ln -s ../public/js $@

css:
	ln -s ../public/css $@

fonts:
	ln -s ../public/fonts $@

favicon.ico:
	ln -s ../public/favicon.ico $@

