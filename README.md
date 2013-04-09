## Jenkins Annotator

Inspired by [the original jenkins-commentator by KnpLabs](https://github.com/KnpLabs/jenkins-commentator)

This spin-off is written in PHP using React and Symfony/Console just because.

Configure your job to call this post-build:

```
curl --data-urlencode out@result.testdox "localhost:8090\
?jenkins=$JENKINS_URL
&user=ShonM\
&repo=jenkins-annotator\
&sha=$GIT_COMMIT\
&status=$BUILD_STATUS\
&project=$JOB_NAME\
&job=$BUILD_NUMBER"
```

With the EnvInject Plugin, under Build > Inject environemnt variables > Properties Content, pass: `BUILD_STATUS=success`