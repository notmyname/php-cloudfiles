#!/bin/sh

php -d include_path=.:.. ./utf8_test.php 2>&1 | tee output.log

