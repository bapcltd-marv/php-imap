<?php

declare(strict_types=1);

namespace PhpImap;

use function count;
use DateTime;
use const DIRECTORY_SEPARATOR;
use Exception;
use InvalidArgumentException;
use PhpImap\Exceptions\ConnectionException;
use PhpImap\Exceptions\InvalidParameterException;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * @see https://github.com/barbushin/php-imap
 *
 * @author Barbushin Sergey http://linkedin.com/in/barbushin
 *
 * @psalm-type PARTSTRUCTURE_PARAM = object{attribute:string, value?:string}
 *
 * @psalm-type PARTSTRUCTURE = object{
 *  id?:string,
 *  encoding:int|mixed,
 *  partStructure:object[],
 *  parameters:PARTSTRUCTURE_PARAM[],
 *  dparameters:object{attribute:string, value:string}[],
 *  parts:array<int, object{disposition?:string}>,
 *  type:int,
 *  subtype:string
 * }
 * @psalm-type HOSTNAMEANDADDRESS_ENTRY = object{host?:string, personal?:string, mailbox:string}
 * @psalm-type HOSTNAMEANDADDRESS = array{0:HOSTNAMEANDADDRESS_ENTRY, 1?:HOSTNAMEANDADDRESS_ENTRY}
 * @psalm-type COMPOSE_ENVELOPE = array{
 *	subject?:string
 * }
 * @psalm-type COMPOSE_BODY = list<array{
 *	type?:int,
 *	encoding?:int,
 *	charset?:string,
 *	subtype?:string,
 *	description?:string,
 *	disposition?:array{filename:string}
 * }>
 *
 * @todo see @todo of Imap::mail_compose()
 */
class Mailbox
{
    const EXPECTED_SIZE_OF_MESSAGE_AS_ARRAY = 2;

    const MAX_LENGTH_FILEPATH = 255;

    const PART_TYPE_TWO = 2;

    /** @var string */
    protected $imapPath;

    /** @var string */
    protected $imapLogin;

    /** @var string */
    protected $imapPassword;

    /** @var string|null */
    protected $imapOAuthAccessToken = null;

    /** @var int */
    protected $imapSearchOption = SE_UID;

    /** @var int */
    protected $connectionRetry = 0;

    /** @var int */
    protected $connectionRetryDelay = 100;

    /** @var int */
    protected $imapOptions = 0;

    /** @var int */
    protected $imapRetriesNum = 0;

    /** @psalm-var array{DISABLE_AUTHENTICATOR?:string} */
    protected $imapParams = [];

    /** @var string */
    protected $serverEncoding = 'UTF-8';

    /** @var string|null */
    protected $attachmentsDir = null;

    /** @var bool */
    protected $expungeOnDisconnect = true;

    /**
     * @var int[]
     *
     * @psalm-var array{1?:int, 2?:int, 3?:int, 4?:int}
     */
    protected $timeouts = [];

    /** @var bool */
    protected $attachmentsIgnore = false;

    /** @var string */
    protected $pathDelimiter = '.';

    /** @var resource|null */
    private $imapStream;

    /**
     * @throws InvalidParameterException
     */
    public function __construct(string $imapPath, string $login, string $password, string $attachmentsDir = null, string $serverEncoding = 'UTF-8')
    {
        $this->imapPath = \trim($imapPath);
        $this->imapLogin = \trim($login);
        $this->imapPassword = $password;
        $this->setServerEncoding($serverEncoding);
        if (null != $attachmentsDir) {
            $this->setAttachmentsDir($attachmentsDir);
        }
    }

    /**
     * Disconnects from the IMAP server / mailbox.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Sets / Changes the OAuth Token for the authentication.
     *
     * @param string $access_token OAuth token from your application (eg. Google Mail)
     *
     * @throws InvalidArgumentException If no access token is provided
     * @throws Exception                If OAuth authentication was unsuccessful
     */
    public function setOAuthToken(string $access_token)
    {
        if (empty(\trim($access_token))) {
            throw new InvalidParameterException('setOAuthToken() requires an access token as parameter!');
        }

        $this->imapOAuthAccessToken = \trim($access_token);

        try {
            $this->_oauthAuthentication();
        } catch (Exception $ex) {
            throw new Exception('Invalid OAuth token provided. Error: '.$ex->getMessage());
        }
    }

    /**
     * Gets the OAuth Token for the authentication.
     *
     * @return string|null $access_token OAuth Access Token
     */
    public function getOAuthToken()
    {
        return $this->imapOAuthAccessToken;
    }

    /**
     * Sets / Changes the path delimiter character (Supported values: '.', '/').
     *
     * @param string $delimiter Path delimiter
     *
     * @throws InvalidParameterException
     */
    public function setPathDelimiter(string $delimiter)
    {
        if (!$this->validatePathDelimiter($delimiter)) {
            throw new InvalidParameterException('setPathDelimiter() can only set the delimiter to these characters: ".", "/"');
        }

        $this->pathDelimiter = $delimiter;
    }

    /**
     * Returns the current set path delimiter character.
     *
     * @return string Path delimiter
     */
    public function getPathDelimiter(): string
    {
        return $this->pathDelimiter;
    }

    /**
     * Validates the given path delimiter character.
     *
     * @param string $delimiter Path delimiter
     *
     * @return bool true (supported) or false (unsupported)
     */
    public function validatePathDelimiter(string $delimiter): bool
    {
        $supported_delimiters = ['.', '/'];

        if (!\in_array($delimiter, $supported_delimiters)) {
            return false;
        }

        return true;
    }

    /**
     * Returns the current set server encoding.
     *
     * @return string Server encoding (eg. 'UTF-8')
     */
    public function getServerEncoding(): string
    {
        return $this->serverEncoding;
    }

    /**
     * Sets / Changes the server encoding.
     *
     * @param string $serverEncoding Server encoding (eg. 'UTF-8')
     *
     * @throws InvalidParameterException
     */
    public function setServerEncoding(string $serverEncoding)
    {
        $serverEncoding = \strtoupper(\trim($serverEncoding));

        $supported_encodings = \array_map('strtoupper', \mb_list_encodings());

        if (!\in_array($serverEncoding, $supported_encodings) && 'US-ASCII' != $serverEncoding) {
            throw new InvalidParameterException('"'.$serverEncoding.'" is not supported by setServerEncoding(). Your system only supports these encodings: US-ASCII, '.\implode(', ', $supported_encodings));
        }

        $this->serverEncoding = $serverEncoding;
    }

    /**
     * Returns the current set IMAP search option.
     *
     * @return int IMAP search option (eg. 'SE_UID')
     */
    public function getImapSearchOption(): int
    {
        return $this->imapSearchOption;
    }

    /**
     * Sets / Changes the IMAP search option.
     *
     * @param int $imapSearchOption IMAP search option (eg. 'SE_UID')
     *
     * @psalm-param 1|2 $imapSearchOption
     *
     * @throws InvalidParameterException
     */
    public function setImapSearchOption(int $imapSearchOption)
    {
        $supported_options = [SE_FREE, SE_UID];

        if (!\in_array($imapSearchOption, $supported_options, true)) {
            throw new InvalidParameterException('"'.$imapSearchOption.'" is not supported by setImapSearchOption(). Supported options are SE_FREE and SE_UID.');
        }

        $this->imapSearchOption = $imapSearchOption;
    }

    /**
     * Set $this->attachmentsIgnore param. Allow to ignore attachments when they are not required and boost performance.
     */
    public function setAttachmentsIgnore(bool $attachmentsIgnore)
    {
        $this->attachmentsIgnore = $attachmentsIgnore;
    }

    /**
     * Get $this->attachmentsIgnore param.
     *
     * @return bool $attachmentsIgnore
     */
    public function getAttachmentsIgnore(): bool
    {
        return $this->attachmentsIgnore;
    }

    /**
     * Sets the timeout of all or one specific type.
     *
     * @param int   $timeout Timeout in seconds
     * @param array $types   One of the following: IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT
     *
     * @psalm-param list<1|2|3|4> $types
     *
     * @throws InvalidParameterException
     */
    public function setTimeouts(int $timeout, array $types = [IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT])
    {
        $supported_types = [IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT];

        $found_types = \array_intersect($types, $supported_types);

        if (\count($types) != \count($found_types)) {
            throw new InvalidParameterException('You have provided at least one unsupported timeout type. Supported types are: IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT');
        }

        /** @var array{1?:int, 2?:int, 3?:int, 4?:int} */
        $this->timeouts = \array_fill_keys($types, $timeout);
    }

    /**
     * Returns the IMAP login (usually an email address).
     *
     * @return string IMAP login
     */
    public function getLogin(): string
    {
        return $this->imapLogin;
    }

    /**
     * Set custom connection arguments of imap_open method. See http://php.net/imap_open.
     *
     * @param string[]|null $params
     *
     * @psalm-param array{DISABLE_AUTHENTICATOR?:string}|array<empty, empty>|null $params
     *
     * @throws InvalidParameterException
     */
    public function setConnectionArgs(int $options = 0, int $retriesNum = 0, array $params = null)
    {
        if (0 !== $options) {
            $supported_options = [OP_READONLY, OP_ANONYMOUS, OP_HALFOPEN, CL_EXPUNGE, OP_DEBUG, OP_SHORTCACHE, OP_SILENT, OP_PROTOTYPE, OP_SECURE];
            if (!\in_array($options, $supported_options, true)) {
                throw new InvalidParameterException('Please check your option for setConnectionArgs()! Unsupported option "'.$options.'". Available options: https://www.php.net/manual/de/function.imap-open.php');
            }
            $this->imapOptions = $options;
        }

        if (0 != $retriesNum) {
            if ($retriesNum < 0) {
                throw new InvalidParameterException('Invalid number of retries provided for setConnectionArgs()! It must be a positive integer. (eg. 1 or 3)');
            }
            $this->imapRetriesNum = $retriesNum;
        }

        if (\is_array($params) and \count($params) > 0) {
            $supported_params = ['DISABLE_AUTHENTICATOR'];

            foreach (\array_keys($params) as $key) {
                if (!\in_array($key, $supported_params, true)) {
                    throw new InvalidParameterException('Invalid array key of params provided for setConnectionArgs()! Only DISABLE_AUTHENTICATOR is currently valid.');
                }
            }

            $this->imapParams = $params;
        }
    }

    /**
     * Set custom folder for attachments in case you want to have tree of folders for each email
     * i.e. a/1 b/1 c/1 where a,b,c - senders, i.e. john@smith.com.
     *
     * @param string $attachmentsDir Folder where to save attachments
     *
     * @throws InvalidParameterException
     */
    public function setAttachmentsDir(string $attachmentsDir)
    {
        if (empty(\trim($attachmentsDir))) {
            throw new InvalidParameterException('setAttachmentsDir() expects a string as first parameter!');
        }
        if (!\is_dir($attachmentsDir)) {
            throw new InvalidParameterException('Directory "'.$attachmentsDir.'" not found');
        }
        $this->attachmentsDir = \rtrim(\realpath($attachmentsDir), '\\/');
    }

    /**
     * Get current saving folder for attachments.
     *
     * @return string|null Attachments dir
     */
    public function getAttachmentsDir()
    {
        return $this->attachmentsDir;
    }

    /**
     * Sets / Changes the attempts / retries to connect.
     */
    public function setConnectionRetry(int $maxAttempts)
    {
        $this->connectionRetry = $maxAttempts;
    }

    /**
     * Sets / Changes the delay between each attempt / retry to connect.
     */
    public function setConnectionRetryDelay(int $milliseconds)
    {
        $this->connectionRetryDelay = $milliseconds;
    }

    /**
     * Get IMAP mailbox connection stream.
     *
     * @param bool $forceConnection Initialize connection if it's not initialized
     *
     * @return resource
     */
    public function getImapStream(bool $forceConnection = true)
    {
        if ($forceConnection) {
            $this->pingOrDisconnect();
            if (!$this->imapStream) {
                $this->imapStream = $this->initImapStreamWithRetry();
            }
        }

        /** @var resource */
        return $this->imapStream;
    }

    public function hasImapStream(): bool
    {
        return \is_resource($this->imapStream) && \imap_ping($this->imapStream);
    }

    /**
     * Returns the provided string in UTF7-IMAP encoded format.
     *
     * @return string $str UTF-7 encoded string
     */
    public function encodeStringToUtf7Imap(string $str): string
    {
        $out = \mb_convert_encoding($str, 'UTF7-IMAP', \mb_detect_encoding($str, 'UTF-8, ISO-8859-1, ISO-8859-15', true));

        if (!\is_string($out)) {
            throw new UnexpectedValueException('mb_convert_encoding($str, \'UTF-8\', {detected}) could not convert $str');
        }

        return $out;
    }

    /**
     * Returns the provided string in UTF-8 encoded format.
     *
     * @return string $str UTF-7 encoded string or same as before, when it's no string
     */
    public function decodeStringFromUtf7ImapToUtf8(string $str): string
    {
        $out = \mb_convert_encoding($str, 'UTF-8', 'UTF7-IMAP');

        if (!\is_string($out)) {
            throw new UnexpectedValueException('mb_convert_encoding($str, \'UTF-8\', \'UTF7-IMAP\') could not convert $str');
        }

        return $out;
    }

    /**
     * Switch mailbox without opening a new connection.
     *
     * @throws Exception
     */
    public function switchMailbox(string $imapPath, bool $absolute = true)
    {
        if (\strpos($imapPath, '}') > 0) {
            $this->imapPath = $imapPath;
        } else {
            $this->imapPath = $this->getCombinedPath($imapPath, $absolute);
        }

        Imap::reopen($this->getImapStream(), $this->imapPath);
    }

    /**
     * Disconnects from IMAP server / mailbox.
     */
    public function disconnect()
    {
        if ($this->hasImapStream()) {
            Imap::close($this->getImapStream(false), $this->expungeOnDisconnect ? CL_EXPUNGE : 0);
        }
    }

    /**
     * Sets 'expunge on disconnect' parameter.
     */
    public function setExpungeOnDisconnect(bool $isEnabled)
    {
        $this->expungeOnDisconnect = $isEnabled;
    }

    /**
     * Get information about the current mailbox.
     *
     * Returns the information in an object with following properties:
     *  Date - current system time formatted according to RFC2822
     *  Driver - protocol used to access this mailbox: POP3, IMAP, NNTP
     *  Mailbox - the mailbox name
     *  Nmsgs - number of mails in the mailbox
     *  Recent - number of recent mails in the mailbox
     *
     * @see imap_check
     *
     * @return object
     */
    public function checkMailbox()
    {
        return Imap::check($this->getImapStream());
    }

    /**
     * Creates a new mailbox.
     *
     * @param string $name Name of new mailbox (eg. 'PhpImap')
     *
     * @see imap_createmailbox()
     */
    public function createMailbox(string $name)
    {
        Imap::createmailbox($this->getImapStream(), $this->getCombinedPath($name));
    }

    /**
     * Deletes a specific mailbox.
     *
     * @param string $name Name of mailbox, which you want to delete (eg. 'PhpImap')
     *
     * @see imap_deletemailbox()
     */
    public function deleteMailbox(string $name, bool $absolute = false): bool
    {
        return Imap::deletemailbox($this->getImapStream(), $this->getCombinedPath($name, $absolute));
    }

    /**
     * Rename an existing mailbox from $oldName to $newName.
     *
     * @param string $oldName Current name of mailbox, which you want to rename (eg. 'PhpImap')
     * @param string $newName New name of mailbox, to which you want to rename it (eg. 'PhpImapTests')
     */
    public function renameMailbox(string $oldName, string $newName)
    {
        Imap::renamemailbox($this->getImapStream(), $this->getCombinedPath($oldName), $this->getCombinedPath($newName));
    }

    /**
     * Gets status information about the given mailbox.
     *
     * This function returns an object containing status information.
     * The object has the following properties: messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return object
     */
    public function statusMailbox()
    {
        return Imap::status($this->getImapStream(), $this->imapPath, SA_ALL);
    }

    /**
     * Gets listing the folders.
     *
     * This function returns an object containing listing the folders.
     * The object has the following properties: messages, recent, unseen, uidnext, and uidvalidity.
     *
     * @return array listing the folders
     */
    public function getListingFolders(string $pattern = '*'): array
    {
        return Imap::listOfMailboxes($this->getImapStream(), $this->imapPath, $pattern);
    }

    /**
     * This function uses imap_search() to perform a search on the mailbox currently opened in the given IMAP stream.
     * For example, to match all unanswered mails sent by Mom, you'd use: "UNANSWERED FROM mom".
     *
     * @param string $criteria              See http://php.net/imap_search for a complete list of available criteria
     * @param bool   $disableServerEncoding Disables server encoding while searching for mails (can be useful on Exchange servers)
     *
     * @return int[] mailsIds (or empty array)
     *
     * @psalm-return list<int>
     */
    public function searchMailbox(string $criteria = 'ALL', bool $disableServerEncoding = false): array
    {
        if ($disableServerEncoding) {
            /** @psalm-var list<int> */
            return Imap::search($this->getImapStream(), $criteria, $this->imapSearchOption);
        }

        /** @psalm-var list<int> */
        return Imap::search($this->getImapStream(), $criteria, $this->imapSearchOption, $this->getServerEncoding());
    }

    /**
     * Search the mailbox for emails from multiple, specific senders.
     *
     * @see Mailbox::searchMailboxFromWithOrWithoutDisablingServerEncoding()
     *
     * @return int[]
     *
     * @psalm-return list<int>
     */
    public function searchMailboxFrom(string $criteria, string $sender, string ...$senders): array
    {
        return $this->searchMailboxFromWithOrWithoutDisablingServerEncoding($criteria, false, $sender, ...$senders);
    }

    /**
     * Search the mailbox for emails from multiple, specific senders whilst not using server encoding.
     *
     * @see Mailbox::searchMailboxFromWithOrWithoutDisablingServerEncoding()
     *
     * @return int[]
     *
     * @psalm-return list<int>
     */
    public function searchMailboxFromDisableServerEncoding(string $criteria, string $sender, string ...$senders): array
    {
        return $this->searchMailboxFromWithOrWithoutDisablingServerEncoding($criteria, true, $sender, ...$senders);
    }

    /**
     * Search the mailbox using multiple criteria merging the results.
     *
     * @param string $single_criteria
     * @param string ...$criteria
     *
     * @return int[]
     *
     * @psalm-return list<int>
     */
    public function searchMailboxMergeResults($single_criteria, ...$criteria)
    {
        return $this->searchMailboxMergeResultsWithOrWithoutDisablingServerEncoding(false, $single_criteria, ...$criteria);
    }

    /**
     * Search the mailbox using multiple criteria merging the results.
     *
     * @param string $single_criteria
     * @param string ...$criteria
     *
     * @return int[]
     *
     * @psalm-return list<int>
     */
    public function searchMailboxMergeResultsDisableServerEncoding($single_criteria, ...$criteria)
    {
        return $this->searchMailboxMergeResultsWithOrWithoutDisablingServerEncoding(false, $single_criteria, ...$criteria);
    }

    /**
     * Save a specific body section to a file.
     *
     * @param int $mailId message number
     *
     * @see imap_savebody()
     */
    public function saveMail(int $mailId, string $filename = 'email.eml')
    {
        Imap::savebody($this->getImapStream(), $filename, $mailId, '', (SE_UID === $this->imapSearchOption) ? FT_UID : 0);
    }

    /**
     * Marks mails listed in mailId for deletion.
     *
     * @param int $mailId message number
     *
     * @see imap_delete()
     */
    public function deleteMail(int $mailId)
    {
        Imap::delete($this->getImapStream(), $mailId, (SE_UID === $this->imapSearchOption) ? FT_UID : 0);
    }

    /**
     * Moves mails listed in mailId into new mailbox.
     *
     * @param string|int $mailId  a range or message number
     * @param string     $mailBox Mailbox name
     *
     * @see imap_mail_move()
     */
    public function moveMail($mailId, string $mailBox)
    {
        Imap::mail_move($this->getImapStream(), $mailId, $mailBox, CP_UID);
        $this->expungeDeletedMails();
    }

    /**
     * Copies mails listed in mailId into new mailbox.
     *
     * @param string|int $mailId  a range or message number
     * @param string     $mailBox Mailbox name
     *
     * @see imap_mail_copy()
     */
    public function copyMail($mailId, string $mailBox)
    {
        Imap::mail_copy($this->getImapStream(), $mailId, $mailBox, CP_UID);
        $this->expungeDeletedMails();
    }

    /**
     * Deletes all the mails marked for deletion by imap_delete(), imap_mail_move(), or imap_setflag_full().
     *
     * @see imap_expunge()
     */
    public function expungeDeletedMails()
    {
        Imap::expunge($this->getImapStream());
    }

    /**
     * Add the flag \Seen to a mail.
     */
    public function markMailAsRead(int $mailId)
    {
        $this->setFlag([$mailId], '\\Seen');
    }

    /**
     * Remove the flag \Seen from a mail.
     */
    public function markMailAsUnread(int $mailId)
    {
        $this->clearFlag([$mailId], '\\Seen');
    }

    /**
     * Add the flag \Flagged to a mail.
     */
    public function markMailAsImportant(int $mailId)
    {
        $this->setFlag([$mailId], '\\Flagged');
    }

    /**
     * Add the flag \Seen to a mails.
     *
     * @param int[] $mailId
     *
     * @psalm-param list<int> $mailId
     */
    public function markMailsAsRead(array $mailId)
    {
        $this->setFlag($mailId, '\\Seen');
    }

    /**
     * Remove the flag \Seen from some mails.
     *
     * @param int[] $mailId
     *
     * @psalm-param list<int> $mailId
     */
    public function markMailsAsUnread(array $mailId)
    {
        $this->clearFlag($mailId, '\\Seen');
    }

    /**
     * Add the flag \Flagged to some mails.
     *
     * @param int[] $mailId
     *
     * @psalm-param list<int> $mailId
     */
    public function markMailsAsImportant(array $mailId)
    {
        $this->setFlag($mailId, '\\Flagged');
    }

    /**
     * Causes a store to add the specified flag to the flags set for the mails in the specified sequence.
     *
     * @param array  $mailsIds Array of mail IDs
     * @param string $flag     Which you can set are \Seen, \Answered, \Flagged, \Deleted, and \Draft as defined by RFC2060
     */
    public function setFlag(array $mailsIds, string $flag)
    {
        Imap::setflag_full($this->getImapStream(), \implode(',', $mailsIds), $flag, ST_UID);
    }

    /**
     * Causes a store to delete the specified flag to the flags set for the mails in the specified sequence.
     *
     * @param array  $mailsIds Array of mail IDs
     * @param string $flag     Which you can delete are \Seen, \Answered, \Flagged, \Deleted, and \Draft as defined by RFC2060
     */
    public function clearFlag(array $mailsIds, string $flag)
    {
        Imap::clearflag_full($this->getImapStream(), \implode(',', $mailsIds), $flag, ST_UID);
    }

    /**
     * Fetch mail headers for listed mails ids.
     *
     * Returns an array of objects describing one mail header each. The object will only define a property if it exists. The possible properties are:
     *  subject - the mails subject
     *  from - who sent it
     *  sender - who sent it
     *  to - recipient
     *  date - when was it sent
     *  message_id - Mail-ID
     *  references - is a reference to this mail id
     *  in_reply_to - is a reply to this mail id
     *  size - size in bytes
     *  uid - UID the mail has in the mailbox
     *  msgno - mail sequence number in the mailbox
     *  recent - this mail is flagged as recent
     *  flagged - this mail is flagged
     *  answered - this mail is flagged as answered
     *  deleted - this mail is flagged for deletion
     *  seen - this mail is flagged as already read
     *  draft - this mail is flagged as being a draft
     *
     * @return array $mailsIds Array of mail IDs
     *
     * @psalm-return list<object>
     *
     * @todo adjust types & conditionals pending resolution of https://github.com/vimeo/psalm/issues/2619
     */
    public function getMailsInfo(array $mailsIds): array
    {
        $mails = Imap::fetch_overview(
            $this->getImapStream(),
            \implode(',', $mailsIds),
            (SE_UID === $this->imapSearchOption) ? FT_UID : 0
        );
        if (\count($mails)) {
            foreach ($mails as $index => &$mail) {
                if (isset($mail->subject) && !\is_string($mail->subject)) {
                    throw new UnexpectedValueException('subject property at index '.(string) $index.' of argument 1 passed to '.__METHOD__.'() was not a string!');
                }
                if (isset($mail->from) && !\is_string($mail->from)) {
                    throw new UnexpectedValueException('from property at index '.(string) $index.' of argument 1 passed to '.__METHOD__.'() was not a string!');
                }
                if (isset($mail->sender) && !\is_string($mail->sender)) {
                    throw new UnexpectedValueException('sender property at index '.(string) $index.' of argument 1 passed to '.__METHOD__.'() was not a string!');
                }
                if (isset($mail->to) && !\is_string($mail->to)) {
                    throw new UnexpectedValueException('to property at index '.(string) $index.' of argument 1 passed to '.__METHOD__.'() was not a string!');
                }

                if (isset($mail->subject) and !empty(\trim($mail->subject))) {
                    $mail->subject = $this->decodeMimeStr($mail->subject, $this->getServerEncoding());
                }
                if (isset($mail->from) and !empty(\trim($mail->from))) {
                    $mail->from = $this->decodeMimeStr($mail->from, $this->getServerEncoding());
                }
                if (isset($mail->sender) and !empty(\trim($mail->sender))) {
                    $mail->sender = $this->decodeMimeStr($mail->sender, $this->getServerEncoding());
                }
                if (isset($mail->to) and !empty(\trim($mail->to))) {
                    $mail->to = $this->decodeMimeStr($mail->to, $this->getServerEncoding());
                }
            }
        }

        /** @var list<object> */
        return $mails;
    }

    /**
     * Get headers for all messages in the defined mailbox,
     * returns an array of string formatted with header info,
     * one element per mail message.
     *
     * @see imap_headers()
     */
    public function getMailboxHeaders(): array
    {
        return Imap::headers($this->getImapStream());
    }

    /**
     * Get information about the current mailbox.
     *
     * Returns an object with following properties:
     *  Date - last change (current datetime)
     *  Driver - driver
     *  Mailbox - name of the mailbox
     *  Nmsgs - number of messages
     *  Recent - number of recent messages
     *  Unread - number of unread messages
     *  Deleted - number of deleted messages
     *  Size - mailbox size
     *
     * @return object Object with info
     *
     * @see mailboxmsginfo
     */
    public function getMailboxInfo()
    {
        return Imap::mailboxmsginfo($this->getImapStream());
    }

    /**
     * Gets mails ids sorted by some criteria.
     *
     * Criteria can be one (and only one) of the following constants:
     *  SORTDATE - mail Date
     *  SORTARRIVAL - arrival date (default)
     *  SORTFROM - mailbox in first From address
     *  SORTSUBJECT - mail subject
     *  SORTTO - mailbox in first To address
     *  SORTCC - mailbox in first cc address
     *  SORTSIZE - size of mail in octets
     *
     * @param int         $criteria       Sorting criteria (eg. SORTARRIVAL)
     * @param bool        $reverse        Sort reverse or not
     * @param string|null $searchCriteria See http://php.net/imap_search for a complete list of available criteria
     *
     * @psalm-param value-of<Imap::SORT_CRITERIA> $criteria
     * @psalm-param 1|5|0|2|6|3|4 $criteria
     *
     * @return array Mails ids
     */
    public function sortMails(
        int $criteria = SORTARRIVAL,
        bool $reverse = true,
        $searchCriteria = 'ALL',
        string $charset = null
    ): array {
        return Imap::sort(
            $this->getImapStream(),
            $criteria,
            $reverse,
            $this->imapSearchOption,
            $searchCriteria,
            $charset
        );
    }

    /**
     * Get mails count in mail box.
     *
     * @see imap_num_msg()
     */
    public function countMails(): int
    {
        return Imap::num_msg($this->getImapStream());
    }

    /**
     * Return quota limit in KB.
     *
     * @param string $quota_root Should normally be in the form of which mailbox (i.e. INBOX)
     */
    public function getQuotaLimit(string $quota_root = 'INBOX'): int
    {
        $quota = $this->getQuota($quota_root);

        /** @var int */
        return isset($quota['STORAGE']['limit']) ? $quota['STORAGE']['limit'] : 0;
    }

    /**
     * Return quota usage in KB.
     *
     * @param string $quota_root Should normally be in the form of which mailbox (i.e. INBOX)
     *
     * @return int|false FALSE in the case of call failure
     */
    public function getQuotaUsage(string $quota_root = 'INBOX')
    {
        $quota = $this->getQuota($quota_root);

        /** @var int|false */
        return isset($quota['STORAGE']['usage']) ? $quota['STORAGE']['usage'] : 0;
    }

    /**
     * Get raw mail data.
     *
     * @param int  $msgId      ID of the message
     * @param bool $markAsSeen Mark the email as seen, when set to true
     *
     * @return string Message of the fetched body
     */
    public function getRawMail(int $msgId, bool $markAsSeen = true): string
    {
        $options = (SE_UID == $this->imapSearchOption) ? FT_UID : 0;
        if (!$markAsSeen) {
            $options |= FT_PEEK;
        }

        return Imap::fetchbody($this->getImapStream(), $msgId, '', $options);
    }

    /**
     * Get mail header.
     *
     * @param int $mailId ID of the message
     *
     * @throws Exception
     *
     * @todo update type checking pending resolution of https://github.com/vimeo/psalm/issues/2619
     */
    public function getMailHeader(int $mailId): IncomingMailHeader
    {
        $headersRaw = Imap::fetchheader(
            $this->getImapStream(),
            $mailId,
            (SE_UID === $this->imapSearchOption) ? FT_UID : 0
        );

        /** @var object{
         * date?:scalar,
         * Date?:scalar,
         * subject?:scalar,
         * from?:HOSTNAMEANDADDRESS,
         * to?:HOSTNAMEANDADDRESS,
         * cc?:HOSTNAMEANDADDRESS,
         * bcc?:HOSTNAMEANDADDRESS,
         * reply_to?:HOSTNAMEANDADDRESS,
         * sender?:HOSTNAMEANDADDRESS
         * }
         */
        $head = \imap_rfc822_parse_headers($headersRaw);

        if (isset($head->date) && !\is_string($head->date)) {
            throw new UnexpectedValueException('date property of parsed headers corresponding to argument 1 passed to '.__METHOD__.'() was present but not a string!');
        }
        if (isset($head->Date) && !\is_string($head->Date)) {
            throw new UnexpectedValueException('Date property of parsed headers corresponding to argument 1 passed to '.__METHOD__.'() was present but not a string!');
        }
        if (isset($head->subject) && !\is_string($head->subject)) {
            throw new UnexpectedValueException('subject property of parsed headers corresponding to argument 1 passed to '.__METHOD__.'() was present but not a string!');
        }
        if (isset($head->from) && !\is_array($head->from)) {
            throw new UnexpectedValueException('from property of parsed headers corresponding to argument 1 passed to '.__METHOD__.'() was present but not an array!');
        }
        if (isset($head->sender) && !\is_array($head->sender)) {
            throw new UnexpectedValueException('sender property of parsed headers corresponding to argument 1 passed to '.__METHOD__.'() was present but not an array!');
        }
        if (isset($head->to) && !\is_array($head->to)) {
            throw new UnexpectedValueException('to property of parsed headers corresponding to argument 1 passed to '.__METHOD__.'() was present but not an array!');
        }
        if (isset($head->cc) && !\is_array($head->cc)) {
            throw new UnexpectedValueException('cc property of parsed headers corresponding to argument 1 passed to '.__METHOD__.'() was present but not an array!');
        }
        if (isset($head->bcc) && !\is_array($head->bcc)) {
            throw new UnexpectedValueException('bcc property of parsed headers corresponding to argument 1 passed to '.__METHOD__.'() was present but not an array!');
        }
        if (isset($head->reply_to) && !\is_array($head->reply_to)) {
            throw new UnexpectedValueException('reply_to property of parsed headers corresponding to argument 1 passed to '.__METHOD__.'() was present but not an array!');
        }

        $header = new IncomingMailHeader();
        $header->headersRaw = $headersRaw;
        $header->headers = $head;
        $header->id = $mailId;
        $header->isDraft = (!isset($head->date)) ? true : false;
        $header->priority = (\preg_match("/Priority\:(.*)/i", $headersRaw, $matches)) ? \trim($matches[1]) : '';
        $header->importance = (\preg_match("/Importance\:(.*)/i", $headersRaw, $matches)) ? \trim($matches[1]) : '';
        $header->sensitivity = (\preg_match("/Sensitivity\:(.*)/i", $headersRaw, $matches)) ? \trim($matches[1]) : '';
        $header->autoSubmitted = (\preg_match("/Auto-Submitted\:(.*)/i", $headersRaw, $matches)) ? \trim($matches[1]) : '';
        $header->precedence = (\preg_match("/Precedence\:(.*)/i", $headersRaw, $matches)) ? \trim($matches[1]) : '';
        $header->failedRecipients = (\preg_match("/Failed-Recipients\:(.*)/i", $headersRaw, $matches)) ? \trim($matches[1]) : '';

        if (isset($head->date) and !empty(\trim($head->date))) {
            $header->date = self::parseDateTime($head->date);
        } elseif (isset($head->Date) and !empty(\trim($head->Date))) {
            $header->date = self::parseDateTime($head->Date);
        } else {
            $now = new DateTime();
            $header->date = self::parseDateTime($now->format('Y-m-d H:i:s'));
        }

        $header->subject = (isset($head->subject) and !empty(\trim($head->subject))) ? $this->decodeMimeStr($head->subject, $this->getServerEncoding()) : null;
        if (isset($head->from) and !empty($head->from)) {
            list($header->fromHost, $header->fromName, $header->fromAddress) = $this->possiblyGetHostNameAndAddress($head->from);
        } elseif (\preg_match('/smtp.mailfrom=[-0-9a-zA-Z.+_]+@[-0-9a-zA-Z.+_]+.[a-zA-Z]{2,4}/', $headersRaw, $matches)) {
            $header->fromAddress = \substr($matches[0], 14);
        }
        if (isset($head->sender) and !empty($head->sender)) {
            list($header->senderHost, $header->senderName, $header->senderAddress) = $this->possiblyGetHostNameAndAddress($head->sender);
        }
        if (isset($head->to)) {
            $toStrings = [];
            foreach ($head->to as $to) {
                $to_parsed = $this->possiblyGetEmailAndNameFromRecipient($to);
                if ($to_parsed) {
                    list($toEmail, $toName) = $to_parsed;
                    $toStrings[] = $toName ? "$toName <$toEmail>" : $toEmail;
                    $header->to[$toEmail] = $toName;
                }
            }
            $header->toString = \implode(', ', $toStrings);
        }

        if (isset($head->cc)) {
            foreach ($head->cc as $cc) {
                $cc_parsed = $this->possiblyGetEmailAndNameFromRecipient($cc);
                if ($cc_parsed) {
                    $header->cc[$cc_parsed[0]] = $cc_parsed[1];
                }
            }
        }

        if (isset($head->bcc)) {
            foreach ($head->bcc as $bcc) {
                $bcc_parsed = $this->possiblyGetEmailAndNameFromRecipient($bcc);
                if ($bcc_parsed) {
                    $header->bcc[$bcc_parsed[0]] = $bcc_parsed[1];
                }
            }
        }

        if (isset($head->reply_to)) {
            foreach ($head->reply_to as $replyTo) {
                $replyTo_parsed = $this->possiblyGetEmailAndNameFromRecipient($replyTo);
                if ($replyTo_parsed) {
                    $header->replyTo[$replyTo_parsed[0]] = $replyTo_parsed[1];
                }
            }
        }

        if (isset($head->message_id)) {
            if (!\is_string($head->message_id)) {
                throw new UnexpectedValueException('Message ID was expected to be a string, '.\gettype($head->message_id).' found!');
            }
            $header->messageId = $head->message_id;
        }

        return $header;
    }

    /**
     * taken from https://www.electrictoolbox.com/php-imap-message-parts/.
     *
     * @param stdClass[] $messageParts
     * @param stdClass[] $flattenedParts
     *
     * @psalm-param array<string, PARTSTRUCTURE> $flattenedParts
     *
     * @return stdClass[]
     *
     * @psalm-return array<string, PARTSTRUCTURE>
     */
    public function flattenParts(array $messageParts, array $flattenedParts = [], string $prefix = '', int $index = 1, bool $fullPrefix = true): array
    {
        foreach ($messageParts as $part) {
            $flattenedParts[$prefix.$index] = $part;
            if (isset($part->parts)) {
                /** @var stdClass[] */
                $part_parts = $part->parts;

                if (self::PART_TYPE_TWO == $part->type) {
                    /** @var array<string, stdClass> */
                    $flattenedParts = $this->flattenParts($part_parts, $flattenedParts, $prefix.$index.'.', 0, false);
                } elseif ($fullPrefix) {
                    /** @var array<string, stdClass> */
                    $flattenedParts = $this->flattenParts($part_parts, $flattenedParts, $prefix.$index.'.');
                } else {
                    /** @var array<string, stdClass> */
                    $flattenedParts = $this->flattenParts($part_parts, $flattenedParts, $prefix);
                }
                unset($flattenedParts[$prefix.$index]->parts);
            }
            ++$index;
        }

        /** @var array<string, stdClass> */
        return $flattenedParts;
    }

    /**
     * Get mail data.
     *
     * @param int  $mailId     ID of the mail
     * @param bool $markAsSeen Mark the email as seen, when set to true
     */
    public function getMail(int $mailId, bool $markAsSeen = true): IncomingMail
    {
        $mail = new IncomingMail();
        $mail->setHeader($this->getMailHeader($mailId));

        $mailStructure = Imap::fetchstructure(
            $this->getImapStream(),
            $mailId,
            (SE_UID === $this->imapSearchOption) ? FT_UID : 0
        );

        if (empty($mailStructure->parts)) {
            $this->initMailPart($mail, $mailStructure, 0, $markAsSeen);
        } else {
            /** @var array<string, stdClass> */
            $parts = $mailStructure->parts;
            foreach ($this->flattenParts($parts) as $partNum => $partStructure) {
                $this->initMailPart($mail, $partStructure, $partNum, $markAsSeen);
            }
        }

        return $mail;
    }

    /**
     * Download attachment.
     *
     * @param array  $params        Array of params of mail
     * @param object $partStructure Part of mail
     * @param int    $_mailId       ID of mail
     * @param bool   $emlOrigin     True, if it indicates, that the attachment comes from an EML (mail) file
     *
     * @psalm-param array<string, string> $params
     * @psalm-param PARTSTRUCTURE $partStructure
     *
     * @return IncomingMailAttachment $attachment
     *
     * @todo consider "requiring" psalm (suggest + conflict) then setting $params to array<string, string>
     */
    public function downloadAttachment(DataPartInfo $dataInfo, array $params, $partStructure, int $_mailId, bool $emlOrigin = false): IncomingMailAttachment
    {
        if ('RFC822' == $partStructure->subtype && isset($partStructure->disposition) && 'attachment' == $partStructure->disposition) {
            $fileName = \strtolower($partStructure->subtype).'.eml';
        } elseif ('ALTERNATIVE' == $partStructure->subtype) {
            $fileName = \strtolower($partStructure->subtype).'.eml';
        } elseif ((!isset($params['filename']) or empty(\trim($params['filename']))) && (!isset($params['name']) or empty(\trim($params['name'])))) {
            $fileName = \strtolower($partStructure->subtype);
        } else {
            $fileName = (isset($params['filename']) and !empty(\trim($params['filename']))) ? $params['filename'] : $params['name'];
            $fileName = $this->decodeMimeStr($fileName, $this->getServerEncoding());
            $fileName = $this->decodeRFC2231($fileName, $this->getServerEncoding());
        }

        $partStructure_id = ($partStructure->ifid && isset($partStructure->id)) ? $partStructure->id : null;

        $attachment = new IncomingMailAttachment();
        $attachment->id = \sha1($fileName.(isset($partStructure_id) ? $partStructure_id : ''));
        $attachment->contentId = isset($partStructure_id) ? \trim($partStructure_id, ' <>') : null;
        $attachment->name = $fileName;
        $attachment->disposition = (isset($partStructure->disposition) && \is_string($partStructure->disposition)) ? $partStructure->disposition : null;

        /** @var scalar|array|object|resource|null */
        $charset = isset($params['charset']) ? $params['charset'] : null;

        if (isset($charset) && !\is_string($charset)) {
            throw new InvalidArgumentException('Argument 2 passed to '.__METHOD__.'() must specify charset as a string when specified!');
        }
        $attachment->charset = (isset($charset) and !empty(\trim($charset))) ? $charset : null;
        $attachment->emlOrigin = $emlOrigin;

        $attachment->addDataPartInfo($dataInfo);

        $attachmentsDir = $this->getAttachmentsDir();

        if (null != $attachmentsDir) {
            $fileSysName = \bin2hex(\random_bytes(16)).'.bin';
            $filePath = $attachmentsDir.DIRECTORY_SEPARATOR.$fileSysName;

            if (\strlen($filePath) > self::MAX_LENGTH_FILEPATH) {
                $ext = \pathinfo($filePath, PATHINFO_EXTENSION);
                $filePath = \substr($filePath, 0, self::MAX_LENGTH_FILEPATH - 1 - \strlen($ext)).'.'.$ext;
            }
            $attachment->setFilePath($filePath);
            $attachment->saveToDisk();
        }

        return $attachment;
    }

    /**
     * Decodes a mime string.
     *
     * @param string $string MIME string to decode
     *
     * @return string Converted string if conversion was successful, or the original string if not
     *
     * @throws Exception
     *
     * @todo update implementation pending resolution of https://github.com/vimeo/psalm/issues/2619 & https://github.com/vimeo/psalm/issues/2620
     */
    public function decodeMimeStr(string $string, string $toCharset = 'utf-8'): string
    {
        if (empty(\trim($string))) {
            throw new Exception('decodeMimeStr() Can not decode an empty string!');
        }

        $newString = '';
        /** @var list<object{charset?:string, text?:string}>|false */
        $elements = \imap_mime_header_decode($string);

        if (false === $elements) {
            return $newString;
        }

        foreach ($elements as $element) {
            if (isset($element->text)) {
                $fromCharset = !isset($element->charset) ? 'iso-8859-1' : $element->charset;
                // Convert to UTF-8, if string has UTF-8 characters to avoid broken strings. See https://github.com/barbushin/php-imap/issues/232
                $toCharset = isset($element->charset) && \preg_match('/(UTF\-8)|(default)/i', $element->charset) ? 'UTF-8' : $toCharset;
                $newString .= $this->convertStringEncoding($element->text, $fromCharset, $toCharset);
            }
        }

        return $newString;
    }

    public function isUrlEncoded(string $string): bool
    {
        $hasInvalidChars = \preg_match('#[^%a-zA-Z0-9\-_\.\+]#', $string);
        $hasEscapedChars = \preg_match('#%[a-zA-Z0-9]{2}#', $string);

        return !$hasInvalidChars && $hasEscapedChars;
    }

    /**
     * Converts the datetime to a RFC 3339 compliant format.
     *
     * @param string $dateHeader Header datetime
     *
     * @return string RFC 3339 compliant format or original (unchanged) format,
     *                if conversation is not possible
     */
    public function parseDateTime(string $dateHeader): string
    {
        if (empty(\trim($dateHeader))) {
            throw new InvalidParameterException('parseDateTime() expects parameter 1 to be a parsable string datetime');
        }

        $dateHeaderUnixtimestamp = \strtotime($dateHeader);

        if (!$dateHeaderUnixtimestamp) {
            return $dateHeader;
        }

        $dateHeaderRfc3339 = \date(DATE_RFC3339, $dateHeaderUnixtimestamp);

        if (!$dateHeaderRfc3339) {
            return $dateHeader;
        }

        return $dateHeaderRfc3339;
    }

    /**
     * Converts a string from one encoding to another.
     *
     * @param string $string       the string, which you want to convert
     * @param string $fromEncoding the current charset (encoding)
     * @param string $toEncoding   the new charset (encoding)
     *
     * @return string Converted string if conversion was successful, or the original string if not
     */
    public function convertStringEncoding(string $string, string $fromEncoding, string $toEncoding): string
    {
        if (\preg_match('/default|ascii/i', $fromEncoding) || !$string || $fromEncoding == $toEncoding) {
            return $string;
        }
        $supportedEncodings = \array_map('strtolower', \mb_list_encodings());
        if (\in_array(\strtolower($fromEncoding), $supportedEncodings) && \in_array(\strtolower($toEncoding), $supportedEncodings)) {
            $convertedString = \mb_convert_encoding($string, $toEncoding, $fromEncoding);
        } else {
            $convertedString = @\iconv($fromEncoding, $toEncoding.'//TRANSLIT//IGNORE', $string);
        }
        if (('' == $convertedString) or (false === $convertedString)) {
            return $string;
        }

        return $convertedString;
    }

    /**
     * Gets IMAP path.
     */
    public function getImapPath(): string
    {
        return $this->imapPath;
    }

    /**
     * Get message in MBOX format.
     *
     * @param int $mailId message number
     */
    public function getMailMboxFormat(int $mailId): string
    {
        $option = (SE_UID == $this->imapSearchOption) ? FT_UID : 0;

        return \imap_fetchheader($this->getImapStream(), $mailId, $option | FT_PREFETCHTEXT).Imap::body($this->getImapStream(), $mailId, $option);
    }

    /**
     * Get folders list.
     */
    public function getMailboxes(string $search = '*'): array
    {
        /** @psalm-var array<int, scalar|array|object{name?:string}|resource|null> */
        $mailboxes = Imap::getmailboxes($this->getImapStream(), $this->imapPath, $search);

        return $this->possiblyGetMailboxes($mailboxes);
    }

    /**
     * Get folders list.
     */
    public function getSubscribedMailboxes(string $search = '*'): array
    {
        /** @psalm-var array<int, scalar|array|object{name?:string}|resource|null> */
        $mailboxes = Imap::getsubscribed($this->getImapStream(), $this->imapPath, $search);

        return $this->possiblyGetMailboxes($mailboxes);
    }

    /**
     * Subscribe to a mailbox.
     *
     * @throws Exception
     */
    public function subscribeMailbox(string $mailbox)
    {
        Imap::subscribe(
            $this->getImapStream(),
            $this->getCombinedPath($mailbox)
        );
    }

    /**
     * Unsubscribe from a mailbox.
     *
     * @throws Exception
     */
    public function unsubscribeMailbox(string $mailbox)
    {
        Imap::unsubscribe(
            $this->getImapStream(),
            $this->getCombinedPath($mailbox)
        );
    }

    /**
     * Appends $message to $mailbox.
     *
     * @param string|array $message
     *
     * @psalm-param string|array{0:COMPOSE_ENVELOPE, 1:COMPOSE_BODY} $message
     *
     * @return true
     *
     * @see Imap::append()
     */
    public function appendMessageToMailbox(
        $message,
        string $mailbox = '',
        string $options = null,
        string $internal_date = null
    ): bool {
        if (
            \is_array($message) &&
            self::EXPECTED_SIZE_OF_MESSAGE_AS_ARRAY === \count($message) &&
            isset($message[0], $message[1])
        ) {
            $message = Imap::mail_compose($message[0], $message[1]);
        }

        if (!\is_string($message)) {
            throw new InvalidArgumentException('Argument 1 passed to '.__METHOD__.' must be a string or envelope/body pair.');
        }

        return Imap::append(
            $this->getImapStream(),
            $this->getCombinedPath($mailbox),
            $message,
            $options,
            $internal_date
        );
    }

    /**
     * Builds an OAuth2 authentication string for the given email address and access token.
     *
     * @return string $access_token Formatted OAuth access token
     */
    protected function _constructAuthString(): string
    {
        return \base64_encode("user=$this->imapLogin\1auth=Bearer $this->imapOAuthAccessToken\1\1");
    }

    /**
     * Authenticates the IMAP client with the OAuth access token.
     *
     * @throws Exception If any error occured
     */
    protected function _oauthAuthentication()
    {
        $oauth_command = 'A AUTHENTICATE XOAUTH2 '.$this->_constructAuthString();

        $oauth_result = \fwrite($this->getImapStream(), $oauth_command);

        if (false === $oauth_result) {
            throw new Exception('Could not authenticate using OAuth!');
        }

        try {
            $this->checkMailbox();
        } catch (Throwable $ex) {
            throw new Exception('OAuth authentication failed! IMAP Error: '.$ex->getMessage());
        }
    }

    /** @return resource */
    protected function initImapStreamWithRetry()
    {
        $retry = $this->connectionRetry;

        do {
            try {
                return $this->initImapStream();
            } catch (ConnectionException $exception) {
            }
        } while (--$retry > 0 && (!$this->connectionRetryDelay || !\usleep($this->connectionRetryDelay * 1000)));

        throw $exception;
    }

    /**
     * Retrieve the quota settings per user.
     *
     * @param string $quota_root Should normally be in the form of which mailbox (i.e. INBOX)
     *
     * @see imap_get_quotaroot()
     */
    protected function getQuota(string $quota_root = 'INBOX'): array
    {
        return Imap::get_quotaroot($this->getImapStream(), $quota_root);
    }

    /**
     * Open an IMAP stream to a mailbox.
     *
     * @throws Exception if an error occured
     *
     * @return resource IMAP stream on success
     */
    protected function initImapStream()
    {
        foreach ($this->timeouts as $type => $timeout) {
            Imap::timeout($type, $timeout);
        }

        $imapStream = Imap::open(
            $this->imapPath,
            $this->imapLogin,
            $this->imapPassword,
            $this->imapOptions,
            $this->imapRetriesNum,
            $this->imapParams
        );

        return $imapStream;
    }

    /**
     * @param object   $partStructure
     * @param string|0 $partNum
     *
     * @psalm-param PARTSTRUCTURE $partStructure
     *
     * @todo refactor type checking pending resolution of https://github.com/vimeo/psalm/issues/2619
     */
    protected function initMailPart(IncomingMail $mail, $partStructure, $partNum, bool $markAsSeen = true, bool $emlParse = false)
    {
        if (!isset($mail->id)) {
            throw new InvalidArgumentException('Argument 1 passeed to '.__METHOD__.'() did not have the id property set!');
        }

        $options = (SE_UID === $this->imapSearchOption) ? FT_UID : 0;

        if (!$markAsSeen) {
            $options |= FT_PEEK;
        }
        $dataInfo = new DataPartInfo($this, $mail->id, $partNum, $partStructure->encoding, $options);

        /** @var array<string, string> */
        $params = [];
        if (!empty($partStructure->parameters)) {
            foreach ($partStructure->parameters as $param) {
                $params[\strtolower($param->attribute)] = '';
                $value = isset($param->value) ? $param->value : null;
                if (isset($value) && '' !== \trim($value)) {
                    $params[\strtolower($param->attribute)] = $this->decodeMimeStr($value);
                }
            }
        }
        if (!empty($partStructure->dparameters)) {
            foreach ($partStructure->dparameters as $param) {
                $paramName = \strtolower(\preg_match('~^(.*?)\*~', $param->attribute, $matches) ? $matches[1] : $param->attribute);
                if (isset($params[$paramName])) {
                    $params[$paramName] .= $param->value;
                } else {
                    $params[$paramName] = $param->value;
                }
            }
        }

        $isAttachment = isset($params['filename']) || isset($params['name']);

        // ignore contentId on body when mail isn't multipart (https://github.com/barbushin/php-imap/issues/71)
        if (!$partNum && TYPETEXT === $partStructure->type) {
            $isAttachment = false;
        }

        if ($isAttachment) {
            $mail->setHasAttachments(true);
        }

        // check if the part is a subpart of another attachment part (RFC822)
        if ('RFC822' === $partStructure->subtype && isset($partStructure->disposition) && 'attachment' === $partStructure->disposition) {
            // Although we are downloading each part separately, we are going to download the EML to a single file
            //incase someone wants to process or parse in another process
            $attachment = self::downloadAttachment($dataInfo, $params, $partStructure, $mail->id, false);
            $mail->addAttachment($attachment);
        }

        // If it comes from an EML file it is an attachment
        if ($emlParse) {
            $isAttachment = true;
        }

        // Do NOT parse attachments, when getAttachmentsIgnore() is true
        if ($this->getAttachmentsIgnore()
            && (TYPEMULTIPART !== $partStructure->type
            && (TYPETEXT !== $partStructure->type || !\in_array(\mb_strtolower($partStructure->subtype), ['plain', 'html'], true)))
        ) {
            return;
        }

        if ($isAttachment) {
            $attachment = self::downloadAttachment($dataInfo, $params, $partStructure, $mail->id, $emlParse);
            $mail->addAttachment($attachment);
        } else {
            if (isset($params['charset']) && !empty(\trim($params['charset']))) {
                $dataInfo->charset = $params['charset'];
            }
        }

        if (!empty($partStructure->parts)) {
            foreach ($partStructure->parts as $subPartNum => $subPartStructure) {
                $not_attachment = (!isset($partStructure->disposition) || 'attachment' !== $partStructure->disposition);

                if (TYPEMESSAGE === $partStructure->type && 'RFC822' === $partStructure->subtype && $not_attachment) {
                    $this->initMailPart($mail, $subPartStructure, $partNum, $markAsSeen);
                } elseif (TYPEMULTIPART === $partStructure->type && 'ALTERNATIVE' === $partStructure->subtype && $not_attachment) {
                    // https://github.com/barbushin/php-imap/issues/198
                    $this->initMailPart($mail, $subPartStructure, $partNum, $markAsSeen);
                } elseif ('RFC822' === $partStructure->subtype && isset($partStructure->disposition) && 'attachment' === $partStructure->disposition) {
                    //If it comes from am EML attachment, download each part separately as a file
                    $this->initMailPart($mail, $subPartStructure, $partNum.'.'.($subPartNum + 1), $markAsSeen, true);
                } else {
                    $this->initMailPart($mail, $subPartStructure, $partNum.'.'.($subPartNum + 1), $markAsSeen);
                }
            }
        } else {
            if (TYPETEXT === $partStructure->type) {
                if ('plain' === \mb_strtolower($partStructure->subtype)) {
                    $mail->addDataPartInfo($dataInfo, DataPartInfo::TEXT_PLAIN);
                } elseif (!$partStructure->ifdisposition) {
                    $mail->addDataPartInfo($dataInfo, DataPartInfo::TEXT_HTML);
                } elseif (!\is_string($partStructure->disposition)) {
                    throw new InvalidArgumentException('disposition property of object passed as argument 2 to '.__METHOD__.'() was present but not a string!');
                } elseif ('attachment' !== \mb_strtolower($partStructure->disposition)) {
                    $mail->addDataPartInfo($dataInfo, DataPartInfo::TEXT_HTML);
                }
            } elseif (TYPEMESSAGE === $partStructure->type) {
                $mail->addDataPartInfo($dataInfo, DataPartInfo::TEXT_PLAIN);
            }
        }
    }

    protected function decodeRFC2231(string $string, string $charset = 'utf-8'): string
    {
        if (\preg_match("/^(.*?)'.*?'(.*?)$/", $string, $matches)) {
            $encoding = $matches[1];
            $data = $matches[2];
            if ($this->isUrlEncoded($data)) {
                $string = $this->convertStringEncoding(\urldecode($data), $encoding, $charset);
            }
        }

        return $string;
    }

    /**
     * Combine Subfolder or Folder to the connection.
     * Have the imapPath a folder added to the connection info, then will the $folder added as subfolder.
     * If the parameter $absolute TRUE, then will the connection new builded only with this folder as root element.
     *
     * @param string $folder   Folder, the will added to the path
     * @param bool   $absolute Add folder as root element to the connection and remove all other from this
     *
     * @return string Return the new path
     */
    protected function getCombinedPath(string $folder, bool $absolute = false): string
    {
        if (empty(\trim($folder))) {
            return $this->imapPath;
        } elseif ('}' === \substr($this->imapPath, -1)) {
            return $this->imapPath.$folder;
        } elseif (true === $absolute) {
            $folder = ('/' === $folder) ? '' : $folder;
            $posConnectionDefinitionEnd = \strpos($this->imapPath, '}');

            if (false === $posConnectionDefinitionEnd) {
                throw new UnexpectedValueException('"}" was not present in IMAP path!');
            }

            return \substr($this->imapPath, 0, $posConnectionDefinitionEnd + 1).$folder;
        }

        return $this->imapPath.$this->getPathDelimiter().$folder;
    }

    /**
     * @param object $recipient
     *
     * @return array|null
     *
     * @psalm-return array{0:string, 1:string|null}|null
     */
    protected function possiblyGetEmailAndNameFromRecipient($recipient)
    {
        if (isset($recipient->mailbox, $recipient->host)) {
            /** @var mixed */
            $recipientMailbox = $recipient->mailbox;

            /** @var mixed */
            $recipientHost = $recipient->host;

            /** @var mixed */
            $recipientPersonal = isset($recipient->personal) ? $recipient->personal : null;

            if (!\is_string($recipientMailbox)) {
                throw new UnexpectedValueException('mailbox was present on argument 1 passed to '.__METHOD__.'() but was not a string!');
            } elseif (!\is_string($recipientHost)) {
                throw new UnexpectedValueException('host was present on argument 1 passed to '.__METHOD__.'() but was not a string!');
            } elseif (null !== $recipientPersonal && !\is_string($recipientPersonal)) {
                throw new UnexpectedValueException('personal was present on argument 1 passed to '.__METHOD__.'() but was not a string!');
            }

            if ('' !== \trim($recipientMailbox) && '' !== \trim($recipientHost)) {
                $recipientEmail = \strtolower($recipientMailbox.'@'.$recipientHost);
                $recipientName = (\is_string($recipientPersonal) and '' !== \trim($recipientPersonal)) ? $this->decodeMimeStr($recipientPersonal, $this->getServerEncoding()) : null;

                return [
                    $recipientEmail,
                    $recipientName,
                ];
            }
        }

        return null;
    }

    /**
     * @psalm-param array<int, scalar|array|object{name?:string}|resource|null> $t
     *
     * @todo revisit implementation pending resolution of https://github.com/vimeo/psalm/issues/2619
     */
    protected function possiblyGetMailboxes(array $t): array
    {
        $arr = [];
        if ($t) {
            foreach ($t as $index => $item) {
                if (!\is_object($item)) {
                    throw new UnexpectedValueException('Index '.(string) $index.' of argument 1 passed to '.__METHOD__.'() corresponds to a non-object value, '.\gettype($item).' given!');
                }
                /** @var scalar|array|object|resource|null */
                $item_name = isset($item->name) ? $item->name : null;

                if (!isset($item->name, $item->attributes, $item->delimiter)) {
                    throw new UnexpectedValueException('The object at index '.(string) $index.' of argument 1 passed to '.__METHOD__.'() was missing one or more of the required properties "name", "attributes", "delimiter"!');
                } elseif (!\is_string($item_name)) {
                    throw new UnexpectedValueException('The object at index '.(string) $index.' of argument 1 passed to '.__METHOD__.'() has a non-string value for the name property!');
                }

                // https://github.com/barbushin/php-imap/issues/339
                $name = $this->decodeStringFromUtf7ImapToUtf8($item_name);
                $name_pos = \strpos($name, '}');
                if (false === $name_pos) {
                    throw new UnexpectedValueException('Expected token "}" not found in subscription name!');
                }
                $arr[] = [
                    'fullpath' => $name,
                    'attributes' => $item->attributes,
                    'delimiter' => $item->delimiter,
                    'shortpath' => \substr($name, $name_pos + 1),
                ];
            }
        }

        return $arr;
    }

    /**
     * @psalm-param HOSTNAMEANDADDRESS $t
     *
     * @psalm-return array{0:string|null, 1:string|null, 2:string}
     */
    protected function possiblyGetHostNameAndAddress(array $t): array
    {
        $out = [
            isset($t[0]->host) ? $t[0]->host : (isset($t[1], $t[1]->host) ? $t[1]->host : null),
            1 => null,
        ];
        foreach ([0, 1] as $index) {
            $maybe = isset($t[$index], $t[$index]->personal) ? $t[$index]->personal : null;
            if (\is_string($maybe) && '' !== \trim($maybe)) {
                $out[1] = $this->decodeMimeStr($maybe, $this->getServerEncoding());

                break;
            }
        }

        /** @var string */
        $out[] = \strtolower($t[0]->mailbox.'@'.(string) $out[0]);

        /** @var array{0:string|null, 1:string|null, 2:string} */
        return $out;
    }

    /**
     * @todo revisit redundant condition issues pending fix of https://github.com/vimeo/psalm/issues/2626
     */
    protected function pingOrDisconnect()
    {
        if ($this->imapStream && !Imap::ping($this->imapStream)) {
            $this->disconnect();
            $this->imapStream = null;
        }
    }

    /**
     * Search the mailbox for emails from multiple, specific senders.
     *
     * This function wraps Mailbox::searchMailbox() to overcome a shortcoming in ext-imap
     *
     * @return int[]
     *
     * @psalm-return list<int>
     */
    protected function searchMailboxFromWithOrWithoutDisablingServerEncoding(string $criteria, bool $disableServerEncoding, string $sender, string ...$senders): array
    {
        \array_unshift($senders, $sender);

        $senders = \array_values(\array_unique(\array_map(
            /**
             * @param string $sender
             *
             * @return string
             */
            static function ($sender) use ($criteria) {
                return $criteria.' FROM '.\mb_strtolower($sender);
            },
            $senders
        )));

        return $this->searchMailboxMergeResultsWithOrWithoutDisablingServerEncoding(
            $disableServerEncoding,
            ...$senders
        );
    }

    /**
     * Search the mailbox using different criteria, then merge the results.
     *
     * @param bool   $disableServerEncoding
     * @param string $single_criteria
     * @param string ...$criteria
     *
     * @return int[]
     *
     * @psalm-return list<int>
     */
    protected function searchMailboxMergeResultsWithOrWithoutDisablingServerEncoding($disableServerEncoding, $single_criteria, ...$criteria)
    {
        \array_unshift($criteria, $single_criteria);

        $criteria = \array_values(\array_unique($criteria));

        $out = [];

        foreach ($criteria as $criterion) {
            $out = \array_merge($out, $this->searchMailbox($criterion, $disableServerEncoding));
        }

        /** @psalm-var list<int> */
        return \array_values(\array_unique($out, SORT_NUMERIC));
    }
}
