<?php
// Icinga PDF Export | (c) 2018 Icinga GmbH | GPLv2

namespace Icinga\Module\Pdfexport;

use Exception;
use Icinga\Application\Logger;
use Icinga\Application\Platform;
use Icinga\File\Storage\StorageInterface;
use Icinga\File\Storage\TemporaryLocalFileStorage;
use ipl\Html\HtmlString;
use LogicException;
use React\ChildProcess\Process;
use React\EventLoop\Factory;
use React\EventLoop\TimerInterface;
use WebSocket\Client;
use WebSocket\ConnectionException;

class HeadlessChrome
{
    /**
     * Line of stderr output identifying the websocket url
     *
     * First matching group is the used port and the second one the browser id.
     */
    const DEBUG_ADDR_PATTERN = '/^DevTools listening on ws:\/\/(127\.0\.0\.1:\d+)\/devtools\/browser\/([\w-]+)$/';

    /** @var string Path to the Chrome binary */
    protected $binary;

    /** @var array Host and port to the remote Chrome */
    protected $remote;

    /**
     * The document to print
     *
     * @var PrintableHtmlDocument
     */
    protected $document;

    /** @var string Target Url */
    protected $url;

    /** @var StorageInterface */
    protected $fileStorage;

    /**
     * Get the path to the Chrome binary
     *
     * @return  string
     */
    public function getBinary()
    {
        return $this->binary;
    }

    /**
     * Set the path to the Chrome binary
     *
     * @param   string  $binary
     *
     * @return  $this
     */
    public function setBinary($binary)
    {
        $this->binary = $binary;

        return $this;
    }

    /**
     * Get host and port combination of the remote chrome
     *
     * @return array
     */
    public function getRemote()
    {
        return $this->remote;
    }

    /**
     * Set host and port combination of a remote chrome
     *
     * @param string $host
     * @param int    $port
     *
     * @return $this
     */
    public function setRemote($host, $port)
    {
        $this->remote = [$host, $port];

        return $this;
    }

    /**
     * Get the target Url
     *
     * @return  string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Set the target Url
     *
     * @param   string  $url
     *
     * @return  $this
     */
    public function setUrl($url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Get the file storage
     *
     * @return  StorageInterface
     */
    public function getFileStorage()
    {
        if ($this->fileStorage === null) {
            $this->fileStorage = new TemporaryLocalFileStorage();
        }

        return $this->fileStorage;
    }

    /**
     * Set the file storage
     *
     * @param   StorageInterface  $fileStorage
     *
     * @return  $this
     */
    public function setFileStorage($fileStorage)
    {
        $this->fileStorage = $fileStorage;

        return $this;
    }

    /**
     * Render the given argument name-value pairs as shell-escaped string
     *
     * @param   array   $arguments
     *
     * @return  string
     */
    public static function renderArgumentList(array $arguments)
    {
        $list = [];

        foreach ($arguments as $name => $value) {
            if ($value !== null) {
                $value = escapeshellarg($value);

                if (! is_int($name)) {
                    if (substr($name, -1) === '=') {
                        $glue = '';
                    } else {
                        $glue = ' ';
                    }

                    $list[] = escapeshellarg($name) . $glue . $value;
                } else {
                    $list[] = $value;
                }
            } else {
                $list[] = escapeshellarg($name);
            }
        }

        return implode(' ', $list);
    }

    /**
     * Use the given HTML as input
     *
     * @param string|PrintableHtmlDocument $html
     * @param bool $asFile
     * @return $this
     */
    public function fromHtml($html, $asFile = false)
    {
        if ($html instanceof PrintableHtmlDocument) {
            $this->document = $html;
        } else {
            $this->document = (new PrintableHtmlDocument())
                ->setContent(HtmlString::create($html));
        }

        if ($asFile) {
            $path = uniqid('icingaweb2-pdfexport-') . '.html';
            $storage = $this->getFileStorage();

            $storage->create($path, $this->document->render());

            $path = $storage->resolvePath($path, true);

            $this->setUrl("file://$path");
        }

        return $this;
    }

    /**
     * Export to PDF
     *
     * @return string
     * @throws Exception
     */
    public function toPdf()
    {
        if ($this->remote !== null) {
            $result = $this->jsonVersion($this->remote[0], $this->remote[1]);
            $parts = explode('/', $result['webSocketDebuggerUrl']);
            $pdf = $this->printToPDF(join(':', $this->remote), end($parts), isset($this->document)
                ? $this->document->getPrintParameters()
                : []);
        } else {
            $browserHome = $this->getFileStorage()->resolvePath('HOME');
            $commandLine = join(' ', [
                escapeshellarg($this->getBinary()),
                static::renderArgumentList([
                    '--bwsi',
                    '--headless',
                    '--disable-gpu',
                    '--no-sandbox',
                    '--no-first-run',
                    '--disable-dev-shm-usage',
                    '--remote-debugging-port=0',
                    '--homedir=' => $browserHome,
                    '--user-data-dir=' => $browserHome
                ])
            ]);

            if (Platform::isLinux()) {
                Logger::debug('Starting browser process: HOME=%s exec %s', $browserHome, $commandLine);
                $chrome = new Process('exec ' . $commandLine, null, ['HOME' => $browserHome]);
            } else {
                Logger::debug('Starting browser process: %s', $commandLine);
                $chrome = new Process($commandLine);
            }

            $loop = Factory::create();

            $killer = $loop->addTimer(10, function (TimerInterface $timer) use ($chrome) {
                $chrome->terminate(6); // SIGABRT
                Logger::error(
                    'Terminated browser process after %d seconds elapsed without the expected output',
                    $timer->getInterval()
                );
            });

            $chrome->start($loop);

            $pdf = null;
            $chrome->stderr->on('data', function ($chunk) use (&$pdf, $chrome, $loop, $killer) {
                Logger::debug('Caught browser output: %s', $chunk);

                if (preg_match(self::DEBUG_ADDR_PATTERN, trim($chunk), $matches)) {
                    $loop->cancelTimer($killer);

                    $pdf = $this->printToPDF($matches[1], $matches[2], isset($this->document)
                        ? $this->document->getPrintParameters()
                        : []);

                    $chrome->terminate();
                }
            });

            $chrome->on('exit', function ($exitCode, $termSignal) {
                Logger::debug('Browser terminated by signal %d and exited with code %d', $termSignal, $exitCode);
            });

            $loop->run();
        }

        return $pdf;
    }

    /**
     * Export to PDF and save as file on disk
     *
     * @return string The path to the file on disk
     */
    public function savePdf()
    {
        $path = uniqid('icingaweb2-pdfexport-') . '.pdf';

        $storage = $this->getFileStorage();
        $storage->create($path, '');

        $path = $storage->resolvePath($path, true);
        file_put_contents($path, $this->toPdf());

        return $path;
    }

    private function printToPDF($socket, $browserId, array $parameters)
    {
        $browser = new Client(sprintf('ws://%s/devtools/browser/%s', $socket, $browserId));

        // Open new tab, get its id
        $result = $this->communicate($browser, 'Target.createTarget', [
            'url'   => 'about:blank'
        ]);
        if (isset($result['targetId'])) {
            $targetId = $result['targetId'];
        } else {
            throw new Exception('Expected target id. Got instead: ' . json_encode($result));
        }

        $page = new Client(sprintf('ws://%s/devtools/page/%s', $socket, $targetId), ['timeout' => 300]);

        // enable page events
        $result = $this->communicate($page, 'Page.enable');
        if (! empty($result)) {
            throw new Exception('Expected empty result. Got instead: ' . json_encode($result));
        }

        if (($url = $this->getUrl()) !== null) {
            // Navigate to target
            $result = $this->communicate($page, 'Page.navigate', [
                'url'   => $url
            ]);
            if (isset($result['frameId'])) {
                $frameId = $result['frameId'];
            } else {
                throw new Exception('Expected navigation frame. Got instead: ' . json_encode($result));
            }

            // wait for page to fully load
            $this->waitFor($page, 'Page.frameStoppedLoading', ['frameId' => $frameId]);
        } elseif (isset($this->document)) {
            // If there's no url to load transfer the document's content directly
            $this->communicate($page, 'Page.setDocumentContent', [
                'frameId'   => $targetId,
                'html'      => $this->document->render()
            ]);
        } else {
            throw new LogicException('Nothing to print');
        }

        // print pdf
        $result = $this->communicate($page, 'Page.printToPDF', array_merge(
            $parameters,
            ['transferMode' => 'ReturnAsBase64', 'printBackground' => true]
        ));
        if (isset($result['data']) && !empty($result['data'])) {
            $pdf = base64_decode($result['data']);
        } else {
            throw new Exception('Expected base64 data. Got instead: ' . json_encode($result));
        }

        $page->close();  // We're done with the tab, tell this the browser

        // close tab
        $result = $this->communicate($browser, 'Target.closeTarget', [
            'targetId' => $targetId
        ]);
        if (! isset($result['success'])) {
            throw new Exception('Expected close confirmation. Got instead: ' . json_encode($result));
        }

        try {
            $browser->close();
        } catch (ConnectionException $e) {
            // For some reason, the browser doesn't send a response
            Logger::debug(sprintf('Failed to close browser connection: ' . $e->getMessage()));
        }

        return $pdf;
    }

    private function renderApiCall($method, $options = null)
    {
        $data = [
            'id' => time(),
            'method' => $method,
            'params' => $options ?: []
        ];

        return json_encode($data, JSON_FORCE_OBJECT);
    }

    private function parseApiResponse($payload)
    {
        $data = json_decode($payload, true);
        if (isset($data['method']) || isset($data['result'])) {
            return $data;
        } elseif (isset($data['error'])) {
            throw new Exception(sprintf(
                'Error response (%s): %s',
                $data['error']['code'],
                $data['error']['message']
            ));
        } else {
            throw new Exception(sprintf('Unknown response received: %s', $payload));
        }
    }

    private function communicate(Client $ws, $method, $params = null)
    {
        Logger::debug('Transmitting CDP call: %s(%s)', $method, $params ? join(',', array_keys($params)) : '');
        $ws->send($this->renderApiCall($method, $params));

        do {
            $response = $this->parseApiResponse($ws->receive());
            $gotEvent = isset($response['method']);

            if ($gotEvent) {
                Logger::debug(
                    'Received CDP event: %s(%s)',
                    $response['method'],
                    join(',', array_keys($response['params']))
                );
            }
        } while ($gotEvent);

        Logger::debug('Received CDP result: %s', empty($response['result'])
            ? 'none'
            : join(',', array_keys($response['result'])));

        return $response['result'];
    }

    private function waitFor(Client $ws, $eventName, array $expectedParams = null)
    {
        Logger::debug(
            'Awaiting CDP event: %s(%s)',
            $eventName,
            $expectedParams ? join(',', array_keys($expectedParams)) : ''
        );

        $wait = true;

        do {
            $response = $this->parseApiResponse($ws->receive());
            if (isset($response['method'])) {
                $method = $response['method'];
                $params = $response['params'];

                Logger::debug('Received CDP event: %s(%s)', $method, join(',', array_keys($params)));

                if ($method === $eventName) {
                    if ($expectedParams !== null) {
                        $diff = array_intersect_assoc($params, $expectedParams);
                        $wait = empty($diff);
                    } else {
                        $wait = false;
                    }
                }
            }
        } while ($wait);

        return $params;
    }

    /**
     * Get the major version number of Chrome or false on failure
     *
     * @return  int|false
     *
     * @throws  Exception
     */
    public function getVersion()
    {
        switch (true)
        {
            case $this->remote !== null:
                $result = $this->jsonVersion($this->remote[0], $this->remote[1]);
                $version = $result['Browser'];
                break;
            case $this->binary !== null:
                $command = new ShellCommand(
                    escapeshellarg($this->getBinary()) . ' ' . static::renderArgumentList(['--version']),
                    false
                );

                $output = $command->execute();

                if ($command->getExitCode() !== 0) {
                    throw new \Exception($output->stderr);
                }

                $version = $output->stdout;
                break;
            default:
                throw new LogicException('Set a binary or remote first');
        }

        if (preg_match('/(\d+)\.[\d.]+/', $version, $match)) {
            return (int) $match[1];
        }

        return false;
    }

    /**
     * Fetch result from the /json/version API endpoint
     *
     * @param string $host
     * @param int    $port
     *
     * @return bool|array
     */
    protected function jsonVersion($host, $port)
    {
        $client = new \GuzzleHttp\Client();
        $response = $client->request('GET', sprintf('http://%s:%s/json/version', $host, $port));

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        return json_decode($response->getBody(), true);
    }
}
