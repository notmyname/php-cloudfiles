#!/bin/sh

php -d include_path=.:.. ./functional_tests.php 2>&1 | tee output.log

