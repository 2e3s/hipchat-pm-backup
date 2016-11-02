<?php
/**
 * Slavcodev Components
 *
 * @author Veaceslav Medvedev <slavcopost@gmail.com>
 * @license http://www.opensource.org/licenses/bsd-license.php
 */

namespace Acme\Hipchat;

use DomainException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tool to export 1-1 chat history.
 *
 * Usage:
 *
 * ~~~bash
 * hipchat hipchat:export <token> <user> [<user>]
 * ~~~
 *
 * @see https://developer.atlassian.com/hipchat/guide/hipchat-rest-api/api-access-tokens
 * @see https://developer.atlassian.com/hipchat/guide/hipchat-rest-api
 * @see https://www.hipchat.com/docs/apiv2/method/view_privatechat_history
 */
final class ExportUserHistory extends Command
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var string
     */
    private $dir;

    /**
     * Constructor.
     *
     * @param Client $client
     * @param string $dir
     */
    public function __construct(Client $client, string $dir)
    {
        if (!is_dir($dir)) {
            throw new DomainException("Directory [{$dir}] must be writable");
        }

        $this->client = $client;
        $this->dir = realpath($dir);

        parent::__construct('hipchat:export');
    }

    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        $this
            ->addArgument('token', InputArgument::REQUIRED, 'Auth token.')
            ->addArgument('users', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'IDs or Emails of one or more users.')
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');

        foreach ($input->getArgument('users') as $user) {
            $this->exportHistory($token, $user);
        }
    }

    /**
     * @param $file Resource backup file
     * @param array $result History search result items
     */
    private function writeData($file, array $result)
    {
        usort($result, function ($row1, $row2) {
            return ($row1['date'] < $row2['date']) ? -1 : 1;
        });
        foreach ($result as $row) {
            if ($row['type'] !== 'message') {
                continue;
            }
            $time = date('Y-m-d H:i:s', strtotime($row['date']));
            $fromName = $row['from']['name'];
            $fromMention = $row['from']['mention_name'];
            fwrite(
                $file,
                "{$time} -- {$fromName} (@{$fromMention})" . PHP_EOL
                . $row['message'] . PHP_EOL
            );
        }
    }

    /**
     *
     * @param string $token
     * @param string $user
     */
    private function exportHistory(string $token, string $user)
    {
        echo "Working on user {$user}...", PHP_EOL;

        $now = date(DATE_ISO8601);
        $limit = 1000;
        $offset = 0;

        // Keep windows compatibility
        $filename = str_replace(':', '-', "{$now}.{$user}.txt");

        $file = fopen("{$this->dir}/{$filename}", 'w');
        if ($file === false) {
            throw new RuntimeException("Impossible to write into {$filename}");
        } else {
            echo "File [{$filename}] created", PHP_EOL;
        }

        try {
            $data = [];
            do {
                echo "Fetching the {$limit} records from {$offset}...", PHP_EOL;

                $response = $this->client->request(
                    'GET',
                    "v2/user/{$user}/history",
                    [
                        'headers' => [
                            'Authorization' => "Bearer {$token}",
                        ],
                        'query' => [
                            'include_deleted' => 'true',
                            'reverse' => 'true',
                            'max-results' => $limit,
                            'start-index' => $offset,
                            'date' => $now,
                            'end-date' => null,
                        ],
                    ]
                );

               $result = json_decode((string) $response->getBody(), true);

                if (!empty($result['items'])) {
                    $data = array_merge($data, $result['items']);

                    $offset += $limit;
                }
            } while (!empty($result['items']));
            $this->writeData($file, $data);
        } catch (ClientException $e) {
            echo $e->getMessage(), PHP_EOL;
        }

        fclose($file);
    }
}
