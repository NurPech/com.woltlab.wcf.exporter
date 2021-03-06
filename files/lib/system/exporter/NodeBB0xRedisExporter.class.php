<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wcf\data\like\Like;
use wcf\system\exception\SystemException;
use wcf\system\importer\ImportHandler;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\PasswordUtil;
use wcf\util\StringUtil;

/**
 * Exporter for NodeBB (Redis).
 * 
 * @author	Tim Duesterhus
 * @copyright	2001-2015 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class NodeBB0xRedisExporter extends AbstractExporter {
	/**
	 * board cache
	 * @var	array
	 */
	protected $boardCache = array();
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$methods
	 */
	protected $methods = array(
		'com.woltlab.wcf.user' => 'Users',
		'com.woltlab.wcf.user.follower' => 'Followers',
		'com.woltlab.wcf.conversation' => 'Conversations',
		'com.woltlab.wcf.conversation.message' => 'ConversationMessages',
		'com.woltlab.wcf.conversation.user' => 'ConversationUsers',
		'com.woltlab.wbb.board' => 'Boards',
		'com.woltlab.wbb.thread' => 'Threads',
		'com.woltlab.wbb.post' => 'Posts',
		'com.woltlab.wbb.like' => 'Likes',
	);
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 100
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::init()
	 */
	public function init() {
		$host = $this->databaseHost;
		$port = 6379;
		if (preg_match('~^([0-9.]+):([0-9]{1,5})$~', $host, $matches)) {
			// simple check, does not care for valid ip addresses
			$host = $matches[1];
			$port = $matches[2];
		}
		
		$this->database = new \Redis();
		$this->database->connect($host, $port);
		
		if ($this->databasePassword) {
			if (!$this->database->auth($this->databasePassword)) {
				throw new SystemException('Could not auth');
			}
		}
		
		if ($this->databaseName) {
			if (!$this->database->select($this->databaseName)) {
				throw new SystemException('Could not select database');
			}
		}
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		$supportedData = array(
			'com.woltlab.wcf.user' => array(
				'com.woltlab.wcf.user.follower',
			),
			'com.woltlab.wcf.conversation' => array(
			),
			'com.woltlab.wbb.board' => array(
				'com.woltlab.wbb.like',
			),
		);
		
		return $supportedData;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$result = $this->database->exists('global');
		if (!$result) {
			throw new SystemException("Cannot find 'global' key in database");
		}
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		return true;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getQueue()
	 */
	public function getQueue() {
		$queue = array();
		
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			$queue[] = 'com.woltlab.wcf.user';
			
			if (in_array('com.woltlab.wcf.user.follower', $this->selectedData)) $queue[] = 'com.woltlab.wcf.user.follower';
			
			// conversation
			if (in_array('com.woltlab.wcf.conversation', $this->selectedData)) {
				$queue[] = 'com.woltlab.wcf.conversation';
				$queue[] = 'com.woltlab.wcf.conversation.message';
			}
		}
		
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
			
			if (in_array('com.woltlab.wbb.like', $this->selectedData)) $queue[] = 'com.woltlab.wbb.like';
		}
		
		return $queue;
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->database->zcard('users:joindate');
	}
	
	/**
	 * Exports users.
	 */
	public function exportUsers($offset, $limit) {
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		$userIDs = $this->database->zrange('users:joindate', $offset, $offset + $limit);
		if (!$userIDs) throw new SystemException('Could not fetch userIDs');
		
		foreach ($userIDs as $userID) {
			$row = $this->database->hgetall('user:'.$userID);
			if (!$row) throw new SystemException('Invalid user');
			
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => intval($row['joindate'] / 1000),
				'banned' => $row['banned'] ? 1 : 0,
				'banReason' => '',
				'lastActivityTime' => intval($row['lastonline'] / 1000),
				'signature' => self::convertMarkdown($row['signature']),
			);
			
			static $gravatarRegex = null;
			if ($gravatarRegex === null) {
				$gravatarRegex = new Regex('https://(?:secure\.)?gravatar\.com/avatar/([a-f0-9]{32})');
			}
			
			if ($gravatarRegex->match($row['picture'])) {
				$matches = $gravatarRegex->getMatches();
				
				if ($matches[1] === md5($row['email'])) {
					$data['enableGravatar'] = 1;
				}
			}
			
			$birthday = \DateTime::createFromFormat('m/d/Y', StringUtil::decodeHTML($row['birthday']));
			// get user options
			$options = array(
				'birthday' => $birthday ? $birthday->format('Y-m-d') : '',
				'homepage' => StringUtil::decodeHTML($row['website']),
				'location' => StringUtil::decodeHTML($row['location']),
			);
			
			$additionalData = array(
				'options' => $options
			);
			
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['uid'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$password = PasswordUtil::getSaltedHash($row['password'], $row['password']);
				$passwordUpdateStatement->execute(array($password, $newUserID));
			}
		}
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		return 1;
	}
	
	/**
	 * Exports boards.
	 */
	public function exportBoards($offset, $limit) {
		$boardIDs = $this->database->zrange('categories:cid', 0, -1);
		if (!$boardIDs) throw new SystemException('Could not fetch boardIDs');
		
		$imported = array();
		foreach ($boardIDs as $boardID) {
			$row = $this->database->hgetall('category:'.$boardID);
			if (!$row) throw new SystemException('Invalid board');
			
			$this->boardCache[$row['parentCid']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['cid'], array(
				'parentID' => ($board['parentCid'] ?: null),
				'position' => $board['order'] ?: 0,
				'boardType' => $board['link'] ? Board::TYPE_LINK : Board::TYPE_BOARD,
				'title' => $board['name'],
				'description' => $board['description'],
				'externalURL' => $board['link']
			));
			
			$this->exportBoardsRecursively($board['cid']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		return $this->database->zcard('topics:tid');
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		$threadIDs = $this->database->zrange('topics:tid', $offset, $offset + $limit);
		if (!$threadIDs) throw new SystemException('Could not fetch threadIDs');
		
		foreach ($threadIDs as $threadID) {
			$row = $this->database->hgetall('topic:'.$threadID);
			if (!$row) throw new SystemException('Invalid thread');
			
			$data = array(
				'boardID' => $row['cid'],
				'topic' => $row['title'],
				'time' => intval($row['timestamp'] / 1000),
				'userID' => $row['uid'],
				'username' => $this->database->hget('user:'.$row['uid'], 'username'),
				'views' => $row['viewcount'],
				'isSticky' => $row['pinned'],
				'isDisabled' => 0,
				'isClosed' => $row['locked'],
				'isDeleted' => $row['deleted'],
				'deleteTime' => TIME_NOW,
			);
			
			$additionalData = array(
				'tags' => $this->database->smembers('topic:'.$threadID.':tags') ?: array()
			);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['tid'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->database->zcard('posts:pid');
	}
	
	/**
	 * Exports posts.
	 */
	public function exportPosts($offset, $limit) {
		$postIDs = $this->database->zrange('posts:pid', $offset, $offset + $limit);
		if (!$postIDs) throw new SystemException('Could not fetch postIDs');
		
		foreach ($postIDs as $postID) {
			$row = $this->database->hgetall('post:'.$postID);
			if (!$row) throw new SystemException('Invalid post');
			
			// TODO: ip address
			$data = array(
				'threadID' => $row['tid'],
				'userID' => $row['uid'],
				'username' => $this->database->hget('user:'.$row['uid'], 'username'),
				'subject' => '',
				'message' => self::convertMarkdown($row['content']),
				'time' => intval($row['timestamp'] / 1000),
				'isDeleted' => $row['deleted'],
				'deleteTime' => TIME_NOW,
				'editorID' => ($row['editor'] ?: null),
				'editor' => $this->database->hget('user:'.$row['editor'], 'username'),
				'lastEditTime' => intval($row['edited'] / 1000),
				'editCount' => $row['edited'] ? 1 : 0
			);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['pid'], $data);
		}
	}
	
	/**
	 * Counts likes.
	 */
	public function countLikes() {
		return $this->database->zcard('users:joindate');
	}
	
	/**
	 * Exports likes.
	 */
	public function exportLikes($offset, $limit) {
		$userIDs = $this->database->zrange('users:joindate', $offset, $offset + $limit);
		if (!$userIDs) throw new SystemException('Could not fetch userIDs');
		
		foreach ($userIDs as $userID) {
			$likes = $this->database->zrange('uid:'.$userID.':upvote', 0, -1);
			
			if ($likes) {
				foreach ($likes as $postID) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wbb.like')->import(0, array(
						'objectID' => $postID,
						'objectUserID' => $this->database->hget('post:'.$postID, 'uid') ?: null,
						'userID' => $userID,
						'likeValue' => Like::LIKE,
						'time' => intval($this->database->zscore('uid:'.$userID.':upvote', $postID) / 1000)
					));
				}
			}
			
			$dislikes = $this->database->zrange('uid:'.$userID.':downvote', 0, -1);
			
			if ($dislikes) {
				foreach ($dislikes as $postID) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wbb.like')->import(0, array(
						'objectID' => $postID,
						'objectUserID' => $this->database->hget('post:'.$postID, 'uid') ?: null,
						'userID' => $userID,
						'likeValue' => Like::DISLIKE,
						'time' => intval($this->database->zscore('uid:'.$userID.':downvote', $postID) / 1000)
					));
				}
			}
		}
	}
	
	/**
	 * Counts followers.
	 */
	public function countFollowers() {
		return $this->database->zcard('users:joindate');
	}
	
	/**
	 * Exports followers.
	 */
	public function exportFollowers($offset, $limit) {
		$userIDs = $this->database->zrange('users:joindate', $offset, $offset + $limit);
		if (!$userIDs) throw new SystemException('Could not fetch userIDs');
		
		foreach ($userIDs as $userID) {
			$followed = $this->database->zrange('following:'.$userID, 0, -1);
			
			if ($followed) {
				foreach ($followed as $followUserID) {
					ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user.follower')->import(0, array(
						'userID' => $userID,
						'followUserID' => $followUserID,
						'time' => intval($this->database->zscore('following:'.$userID, $followUserID) / 1000)
					));
				}
			}
		}
	}
	
	/**
	 * Counts conversations.
	 */
	public function countConversations() {
		return $this->database->zcard('users:joindate');
	}
	
	/**
	 * Exports conversations.
	 */
	public function exportConversations($offset, $limit) {
		$userIDs = $this->database->zrange('users:joindate', $offset, $offset + $limit);
		if (!$userIDs) throw new SystemException('Could not fetch userIDs');
		
		foreach ($userIDs as $userID) {
			$chats = $this->database->zrange('uid:'.$userID.':chats', 0, -1);
			
			if ($chats) {
				foreach ($chats as $chat) {
					$conversationID = min($userID, $chat).':to:'.max($userID, $chat);
					$firstMessageID = $this->database->zrange('messages:uid:'.$conversationID, 0, 0);
					if (!$firstMessageID) throw new SystemException('Could not find first message of conversation');
					
					$firstMessage = $this->database->hgetall('message:'.$firstMessageID[0]);
					ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation')->import($conversationID, array(
						'subject' => $this->database->hget('user:'.$userID, 'username').' - '.$this->database->hget('user:'.$chat, 'username'),
						'time' => intval($firstMessage['timestamp'] / 1000),
						'userID' => $userID,
						'username' => $this->database->hget('user:'.$firstMessage['fromuid'], 'username'),
						'isDraft' => 0
					));
					
					// participant a
					ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
						'conversationID' => $conversationID,
						'participantID' => $userID,
						'username' => $this->database->hget('user:'.$userID, 'username'),
						'hideConversation' => 0,
						'isInvisible' => 0,
						'lastVisitTime' => 0
					));
					
					// participant b
					ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.user')->import(0, array(
						'conversationID' => $conversationID,
						'participantID' => $chat,
						'username' => $this->database->hget('user:'.$chat, 'username'),
						'hideConversation' => 0,
						'isInvisible' => 0,
						'lastVisitTime' => 0
					));
				}
			}
		}
	}
	
	/**
	 * Counts conversation messages.
	 */
	public function countConversationMessages() {
		return $this->database->hget('global', 'nextMid');
	}
	
	/**
	 * Exports conversation messages.
	 */
	public function exportConversationMessages($offset, $limit) {
		for ($i = 1; $i <= $limit; $i++) {
			$message = $this->database->hgetall('message:'.($offset + $i));
			if (!$message) continue;
			$conversationID = min($message['fromuid'], $message['touid']).':to:'.max($message['fromuid'], $message['touid']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wcf.conversation.message')->import($offset + $i, array(
				'conversationID' => $conversationID,
				'userID' => $message['fromuid'],
				'username' => $this->database->hget('user:'.$message['fromuid'], 'username'),
				'message' => self::convertMarkdown($message['content']),
				'time' => intval($message['timestamp'] / 1000),
				'attachments' => 0,
				'enableSmilies' => 1,
				'enableHtml' => 0,
				'enableBBCodes' => 0,
				'showSignature' => 0
			));
		}
	}
	
	protected static function convertMarkdown($message) {
		static $parsedown = null;
		static $codeRegex = null;
		static $imgRegex = null;
		static $urlRegex = null;
		
		if ($parsedown === null) {
			require_once(WCF_DIR.'lib/system/api/parsedown/Parsedown.php');
			$parsedown = new \Parsedown();
			
			$codeRegex = new Regex('<pre><code class="language-([a-z]+)">');
			$imgRegex = new Regex('<img src="([^"]+)"(?: alt="(?:[^"]+)")? />');
			$urlRegex = new Regex('<a href="([^"]+)">');
		}
		
		$out = $parsedown->text($message);
		$out = $codeRegex->replace($out, '[code=\1]');

		$out = strtr($out, array(
			'<p>' => '',
			'</p>' => '',
			'<br />' => '',
			
			'<strong>' => '[b]',
			'</strong>' => '[/b]',
			'<em>' => '[i]',
			'</em>' => '[/i]',
			'<ol>' => '[list=1]',
			'</ol>' => '[/list]',
			'<ul>' => '[list]',
			'</ul>' => '[/list]',
			'<li>' => '[*]',
			'</li>' => '',
			'<pre><code>' => '[code]',
			'</code></pre>' => '[/code]',
			'<code>' => '[tt]',
			'</code>' => '[/tt]',
			'<blockquote>' => '[quote]',
			'</blockquote>' => '[/quote]',
			
			'</a>' => '[/url]'
		));
		
		$out = $imgRegex->replace($out, '[img]\1[/img]');
		$out = $urlRegex->replace($out, '[url=\1]');
		
		return $out;
	}
}
