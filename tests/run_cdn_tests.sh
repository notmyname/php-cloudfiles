#!/bin/sh

php -d include_path=.:.. ./cdn_functional_tests.php 2>&1 | tee output.log

