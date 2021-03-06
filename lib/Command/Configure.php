<?php
declare(strict_types=1);


/**
 * FullTextSearch_ElasticSearch - Use Elasticsearch to index the content of your nextcloud
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\FullTextSearch_ElasticSearch\Command;


use Exception;
use OC\Core\Command\Base;
use OCA\FullTextSearch_ElasticSearch\Service\ConfigService;
use OCA\FullTextSearch_ElasticSearch\Service\MiscService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Class Configure
 *
 * @package OCA\FullTextSearch_ElasticSearch\Command
 */
class Configure extends Base {


	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * Configure constructor.
	 *
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(ConfigService $configService, MiscService $miscService) {
		parent::__construct();

		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('fulltextsearch_elasticsearch:configure')
			 ->addArgument('json', InputArgument::REQUIRED, 'set config')
			 ->setDescription('Configure the installation');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$json = $input->getArgument('json');

		$config = json_decode($json, true);

		if ($config === null) {
			$output->writeln('Invalid JSON');

			return;
		}

		$ak = array_keys($config);
		foreach ($ak as $k) {
			if (array_key_exists($k, $this->configService->defaults)) {
				$this->configService->setAppValue($k, $config[$k]);
			}
		}

		$output->writeln(json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT));
	}


}

