#!/bin/bash
# bash script for SS behat extension setup

# set output color
red=`tput setaf 1`
green=`tput setaf 2`
yellow=`tput setaf 3`
reset=`tput sgr0`

# get current project directory
path="$( pwd )"

# config "base_url" in behat.yml
echo "${green}Copying behat.yml file...${reset}"
cp -fv $path/vendor/silverstripe/behat-extension/appendixes/behat.yml $path/behat.yml
echo "${yellow}Please enter the site URL which you want Behat test to run against and then press ENTER: "
read base_url
sed -i "" "s@base_url:.*@base_url: $base_url@g" $path/behat.yml
echo "${green}base_url: $base_url is set in your behat.yml file successfully!"

# copy files for SS Behat test session running
echo "\n${green}Copying files for SS Behat test session running...${reset}"

cp -fv $path/vendor/silverstripe/behat-extension/appendixes/mysite/_config/behat.yml $path/mysite/_config/behat.yml
echo "Appending TestSessionEnvironment and TestSessionController to $path/mysite/_config/config.yml"
echo "\n" >> $path/mysite/_config/config.yml
cat $path/vendor/silverstripe/behat-extension/appendixes/mysite/_config/config.yml >> $path/mysite/_config/config.yml
cp -Rv $path/vendor/silverstripe/behat-extension/appendixes/mysite/code/testing/ $path/mysite/code/testing/
cp -Rv $path/vendor/silverstripe/behat-extension/appendixes/mysite/tests/ $path/mysite/tests/
echo "" > $path/mysite/tests/fixtures/FakeDatabase.json

# Behat initialization, mysite is the default project name
echo "${green}Behat initialing..."
vendor/bin/behat --init "@mysite"
echo "Done!"
echo "${yellow}Please replace $path/mysite/tests/fixtures/SS-sample.sql with your own test database sql"