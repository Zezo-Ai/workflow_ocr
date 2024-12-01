<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020 Robin Windey <ro.windey@gmail.com>
 *
 * @author Robin Windey <ro.windey@gmail.com>
 *
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

namespace OCA\WorkflowOcr\Service;

use OC\User\NoUserException;
use OCA\WorkflowOcr\Exception\OcrResultEmptyException;
use OCA\WorkflowOcr\Helper\IProcessingFileAccessor;
use OCA\WorkflowOcr\Model\WorkflowSettings;
use OCA\WorkflowOcr\OcrProcessors\IOcrProcessorFactory;
use OCA\WorkflowOcr\Wrapper\IFilesystem;
use OCA\WorkflowOcr\Wrapper\IViewFactory;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

class OcrService implements IOcrService {
	/** @var IOcrProcessorFactory */
	private $ocrProcessorFactory;

	/** @var IGlobalSettingsService */
	private $globalSettingsService;

	/** @var ISystemTagObjectMapper */
	private $systemTagObjectMapper;

	/** @var IUserManager */
	private $userManager;

	/** @var IFilesystem */
	private $filesystem;

	/** @var IUserSession */
	private $userSession;

	/** @var IRootFolder */
	private $rootFolder;

	/** @var IEventService */
	private $eventService;

	/** @var IViewFactory */
	private $viewFactory;

	/** @var IProcessingFileAccessor */
	private $processingFileAccessor;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		IOcrProcessorFactory $ocrProcessorFactory,
		IGlobalSettingsService $globalSettingsService,
		ISystemTagObjectMapper $systemTagObjectMapper,
		IUserManager $userManager,
		IFilesystem $filesystem,
		IUserSession $userSession,
		IRootFolder $rootFolder,
		IEventService $eventService,
		IViewFactory $viewFactory,
		IProcessingFileAccessor $processingFileAccessor,
		LoggerInterface $logger) {
		$this->ocrProcessorFactory = $ocrProcessorFactory;
		$this->globalSettingsService = $globalSettingsService;
		$this->systemTagObjectMapper = $systemTagObjectMapper;
		$this->userManager = $userManager;
		$this->filesystem = $filesystem;
		$this->userSession = $userSession;
		$this->rootFolder = $rootFolder;
		$this->eventService = $eventService;
		$this->viewFactory = $viewFactory;
		$this->processingFileAccessor = $processingFileAccessor;
		$this->logger = $logger;
	}

	/** @inheritdoc */
	public function runOcrProcess(int $fileId, string $uid, WorkflowSettings $settings) : void {
		try {
			$this->initUserEnvironment($uid);

			$file = $this->getNode($fileId);
			$ocrProcessor = $this->ocrProcessorFactory->create($file->getMimeType());
			$globalSettings = $this->globalSettingsService->getGlobalSettings();
			
			try {
				$result = $ocrProcessor->ocrFile($file, $settings, $globalSettings);
			} catch (OcrResultEmptyException $ex) {
				// #232: it's okay to have an empty result if the file was skipped due to OCR mode
				if ($settings->getOcrMode() === WorkflowSettings::OCR_MODE_SKIP_FILE) {
					$this->logger->debug('Skipping empty OCR result for file with id {fileId} because OCR mode is set to \'skip file\'', ['fileId' => $fileId]);
					return;
				}
				throw $ex;
			}

			$this->processTagsAfterSuccessfulOcr($file, $settings);

			$filePath = $file->getPath();
			$fileContent = $result->getFileContent();
			$originalFileExtension = $file->getExtension();
			$newFileExtension = $result->getFileExtension();

			// Only create a new file version if the file OCR result was not empty #130
			if ($result->getRecognizedText() !== '') {
				$newFilePath = $originalFileExtension === $newFileExtension ?
					$filePath :
					$filePath . '.pdf';

				$this->createNewFileVersion($newFilePath, $fileContent, $fileId);
			}

			$this->eventService->textRecognized($result, $file);
		} finally {
			$this->shutdownUserEnvironment();
		}
	}

	/**
	 * * @param string $uid 	The owners userId of the file to be processed
	 */
	private function initUserEnvironment(string $uid) : void {
		/** @var IUser */
		$user = $this->userManager->get($uid);
		if (!$user) {
			throw new NoUserException("User with uid '$uid' was not found");
		}

		$this->userSession->setUser($user);
		$this->filesystem->init($uid, '/' . $uid . '/files');
	}

	private function shutdownUserEnvironment() : void {
		$this->userSession->setUser(null);
	}

	private function getNode(int $fileId) : ?Node {
		/** @var File[] */
		$nodeArr = $this->rootFolder->getById($fileId);
		if (count($nodeArr) === 0) {
			throw new NotFoundException('Could not process file with id \'' . $fileId . '\'. File was not found');
		}
		
		$node = array_shift($nodeArr);

		if (!$node instanceof Node || $node->getType() !== FileInfo::TYPE_FILE) {
			throw new \InvalidArgumentException('Skipping process for file with id \'' . $fileId . '\'. It is not a file');
		}

		return $node;
	}

	private function processTagsAfterSuccessfulOcr(File $file, WorkflowSettings $settings) : void {
		$objectType = 'files';
		$fileId = strval($file->getId());
		$tagsToRemove = $settings->getTagsToRemoveAfterOcr();
		$tagsToAdd = $settings->getTagsToAddAfterOcr();

		foreach ($tagsToRemove as $tagToRemove) {
			try {
				$this->systemTagObjectMapper->unassignTags($fileId, $objectType, $tagToRemove);
			} catch (TagNotFoundException $ex) {
				$this->logger->warning("Cannot remove tag with id '$tagToRemove' because it was not found. Skipping.");
			}
		}

		foreach ($tagsToAdd as $tagToAdd) {
			try {
				$this->systemTagObjectMapper->assignTags($fileId, $objectType, $tagToAdd);
			} catch (TagNotFoundException $ex) {
				$this->logger->warning("Cannot add tag with id '$tagToAdd' because it was not found. Skipping.");
			}
		}
	}

	/**
	 * @param string $filePath The filepath of the file to write
	 * @param string $ocrContent The new filecontent (which was OCR processed)
	 * @param int $fileId The id of the file to write. Used for locking.
	 */
	private function createNewFileVersion(string $filePath, string $ocrContent, int $fileId) : void {
		$dirPath = dirname($filePath);
		$filename = basename($filePath);
		
		$this->processingFileAccessor->setCurrentlyProcessedFileId($fileId);

		try {
			$view = $this->viewFactory->create($dirPath);
			// Create new file or file-version with OCR-file
			// This will trigger 'postWrite' event which would normally
			// add the file to the queue again but this is tackled
			// by the processingFileAccessor.
			$view->file_put_contents($filename, $ocrContent);
		} finally {
			$this->processingFileAccessor->setCurrentlyProcessedFileId(null);
		}
	}
}
