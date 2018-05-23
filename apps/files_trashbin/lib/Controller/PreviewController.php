<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
namespace OCA\Files_Trashbin\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IPreview;
use OCP\IRequest;

class PreviewController extends Controller {

	/** @var IRootFolder */
	private $rootFolder;

	/** @var string */
	private $userId;

	/** @var IMimeTypeDetector */
	private $mimeTypeDetector;

	/** @var IPreview */
	private $previewManager;

	/** @var ITimeFactory */
	private $time;

	public function __construct(string $appName,
								IRequest $request,
								IRootFolder $rootFolder,
								string $userId,
								IMimeTypeDetector $mimeTypeDetector,
								IPreview $previewManager,
								ITimeFactory $time) {
		parent::__construct($appName, $request);

		$this->rootFolder = $rootFolder;
		$this->userId = $userId;
		$this->mimeTypeDetector = $mimeTypeDetector;
		$this->previewManager = $previewManager;
		$this->time = $time;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return DataResponse|Http\FileDisplayResponse
	 */
	public function getPreview(
		int $fileId,
		int $x = 44,
		int $y = 44
	) {

		if ($x === 0 || $y === 0) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			/** @var Folder $trash */
			$trash = $userFolder->getParent()->get('files_trashbin/files');
			$trashFiles = $trash->getById($fileId);

			if (empty($trashFiles)) {
				throw new NotFoundException();
			}

			$trashFile = array_pop($trashFiles);

			if ($trashFile instanceof Folder) {
				return new DataResponse([], Http::STATUS_BAD_REQUEST);
			}

			if ($trashFile->getParent()->getPath() === $trash->getPath()) {
				/** @var File $trashFile */
				$fileName = $trashFile->getName();
				$i = strrpos($fileName, '.');
				if ($i !== false) {
					$fileName = substr($fileName, 0, $i);
				}

				$mimeType = $this->mimeTypeDetector->detectPath($fileName);
			} else {
				$mimeType = $this->mimeTypeDetector->detectPath($trashFile->getName());
			}

			$f = $this->previewManager->getPreview($trashFile, $x, $y, true, IPreview::MODE_FILL, $mimeType);
			$response = new Http\FileDisplayResponse($f, Http::STATUS_OK, ['Content-Type' => $f->getMimeType()]);

			// Cache previews for 24H
			$response->cacheFor(3600 * 24);
			$expires = new \DateTime();
			$expires->setTimestamp($this->time->getTime());
			$expires->add(new \DateInterval('P1D'));
			$response->addHeader('Expires', $expires->format(\DateTime::RFC2822));
			return $response;
		} catch (NotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
	}
}
