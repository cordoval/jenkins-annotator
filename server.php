<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use React\Socket\Server as SocketServer;
use React\Http\Server as HttpServer;
use React\EventLoop\Factory;
use React\Http\Response;
use React\Http\Request;

use Github\Client;

class Annotator
{
    private $data;
    private $token;
    private $client;
    private $prefix = "**Build Status**: ";

    public function __construct($token)
    {
        $this->token = $token;
    }

    private function getClient()
    {
        if ($this->client) {
            $client = $this->client;
        } else {
            $client = new Client;
            $client->authenticate($this->token, null, Client::AUTH_HTTP_TOKEN);
        }

        return $client;
    }

    private function pull()
    {
        $pulls = $this->getClient()->api('pull_request')->all($this->data['user'], $this->data['repo'], 'open');

        foreach ($pulls as $pull) {
            if ($pull['head']['sha'] == $this->data['sha']) {
                return $pull;
            }
        }

        return null;
    }

    private function uncomment()
    {
        $comments = $this->getClient()->api('issue')->comments()->all($this->data['user'], $this->data['repo'], $this->pull['number']);

        foreach ($comments as $comment) {
            if (strpos($comment['body'], $this->prefix) !== false) {
                $this->getClient()->api('issue')->comments()->remove($this->data['user'], $this->data['repo'], $comment['id']);
            }
        }
    }

    private function comment()
    {
        $body = $this->prefix;
        $body .= ($this->data['status'] == 'success') ? '[Success]' : '[Failure]';
        $body .= "({$this->data['jenkins']}/job/{$this->data['project']}/{$this->data['job']})";
        $body .= "\n```\n" . (string) $this->data['out'] . "```";

        $this->getClient()->api('issue')->comments()->create($this->data['user'], $this->data['repo'], $this->pull['number'], array(
            'body' => $body,
        ));
    }

    private function title()
    {
        if (! $this->data['status'] == 'success') {
            $this->data['status'] = false;
        }

        $prefix = ($this->data['status']) ? '[Tests pass] ' : '[Tests fail] ';
        $title = $prefix . preg_replace('/\[Tests (fail|pass)\] /', '', $this->pull['title']);

        $this->getClient()->api('issue')->update($this->data['user'], $this->data['repo'], $this->pull['number'], array(
            'title' => $title,
        ));
    }

    public function run($data)
    {
        $this->data = $data;

        if (! $this->pull = $this->pull()) {
            return null;
        }

        $this->uncomment();
        $this->title();
        $this->comment();
    }
}

class RunCommand extends Command
{
    protected $input;

    protected function configure()
    {
        $this
            ->setName('run')
            ->addArgument('token', InputArgument::REQUIRED, 'Token of the GitHub user Annotator will run as')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Port that Annotator will listen to for connections', 8090)
        ;
    }

    public function request(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;

        if ($request->expectsContinue()) {
            $response->writeContinue();
        }

        $request->on('data', array($this, 'data'));
    }

    public function data($data)
    {
        if ($data) {
            parse_str($data, $data);
            $this->react(array_merge($data, $this->request->getQuery()));

            $this->response->writeHead(200, array('Content-Length' => 0));
            $this->response->end();
        }
    }

    public function react($data)
    {
        $annotator = new Annotator($this->input->getArgument('token'));
        $annotator->run($data);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;

        $loop = Factory::create();
        $socket = new SocketServer($loop);
        $http = new HttpServer($socket, $loop);

        $http->on('request', array($this, 'request'));

        $output->writeln("Annotator listening on port " . $input->getOption('port'));

        $socket->listen($input->getOption('port'));
        $loop->run();
    }
}

$annotator = new Application('Annotator', '0.0.1');
$annotator->add(new RunCommand);
$annotator->run();
