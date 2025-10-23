#!/bin/bash
for i in {1..2}
do
    cd /wwwroot/synaplan.com/public/
    php gmailrefresh.php
    sleep 25
done
