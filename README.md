# exact-php-client

Fork from picqer/exact-php-client. See that repo for a full readme.

In this repo, a callback can be added to the connection that is triggered on every request to, for example, enable logging.

## Composer install
Installing this Exact client for PHP can be done through Composer.

```
composer require picqer/exact-php-client:dev-master
```

And make sure to add the repo:

```
"repositories": [
		...,
		{
			"type": "git",
			"url":  "https://github.com/tweedegolf/exact-php-client.git"
		}
],
```