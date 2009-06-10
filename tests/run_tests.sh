#!/bin/sh

if [ "$1" = "full" ]; then
    # "full" tests also include:
    #   - generating/uploading a large file
    #   - trying to stream/play a video from EJ's account
    php -d include_path=.:.. ./tests.php full 2>&1 | tee output.log
else
    php -d include_path=.:.. ./tests.php 2>&1 | tee output.log
fi

