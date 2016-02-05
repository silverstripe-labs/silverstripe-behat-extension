#Running Behat Tests using Chrome
If you would like to run Behat Tests using Google Chrome here are a few steps I went through to get that setup.

1) [Download the Google Chrome Webdriver](http://chromedriver.storage.googleapis.com/index.html?path=2.8/)

2) Unzip the file, and place the chromedriver file in a known location.

3) Now edit the `behat.yml` file and create a new profile for using Chrome by adding the following below the default profile.

```
      default_session: selenium2
      javascript_session: selenium2
      selenium2:
        browser: chrome
    SilverStripe\BehatExtension\Extension:
      screenshot_path: %behat.paths.base%/_artifacts/screenshots
```

4) Now we need to use the new webdriver with Selenium. 

```
java -jar selenium-server.jar -Dwebdriver.chrome.driver="/path/to/chromedriver"
```

5) Now run your behat steps with the chrome profile.

```
behat @mysite --profile=chrome
```
