<?php
require_once 'PEAR.php';

/**
*   Class to read mbox mail files.
*
*   An mbox mail file is contains plain emails concatenated in one
*   big file. Since each mail starts with "From ", and ends with a newline,
*   they can be separated from each other.
*
*   This class takes a mbox filename in the constructor, generates an
*   index where the mails start and end when calling open() and returns
*   single mails with get(), using the positions in the index.
*
*   With the help of this class, you also can add(), remove() and update()
*   messages in the mbox file. When calling one of this methods, the class
*   checks if the file has been modified since the index was created -
*   changing the file with the wrong positions in the index would very likely
*   corrupt it.
*   This check is not done when retrieving single messages via get(), as this
*   would slow down the process if you retrieve thousands of mails. You can,
*   however, call hasBeenModified() before using get() to check for modification
*   yourself. If the method returns true, you should close() and re-open() the
*   file.
*
*   If something strange happens and you don't know why, activate debugging with
*   setDebug(true). You also can modify the temporary directory in which changed
*   mboxes are stored when adding/removing/modifying by using setTmpDir('/path/');
*
*   @category   Mail
*   @package    Mail_Mbox
*   @author     Roberto Berto <darkelder@php.net>
*   @author     Christian Weiske <cweiske@php.net>
*   @license    LGPL
*   @version    CVS: $Id$
*/
class Mail_Mbox extends PEAR
{
    /**
     * File resource / handle
     *
     * @var      resource
     * @access   protected
     */
    var $_resource = null;

    /**
     * Message index. Each mail has its own subarray,
     * which contains the start position and end position
     * as first and second subindex.
     *
     * @var     array
     * @access  protected
     */
    var $_index = null;

    /**
     * Timestamp at which the file has been modified last.
     *
     * @var     int
     * @access  protected
     */
    var $_lastModified = null;

    /**
     * Debug mode
     *
     * Set to true to turn on debug mode.
     *
     * @var     bool
     * @access  public
     * @see     setDebug()
     * @see     getDebug()
     */
    var $debug = false;

    /**
     * Directory in which the temporary mbox files are created.
     * Even if it's a unix directory, it does work on windows as
     * the only function it's used in is tempnam which automatically
     * chooses the right temp directory if this here doesn't exist.
     * So this variable is for special needs only.
     *
     * @var     string
     * @access  public
     * @see     getTmpDir()
     * @see     setTmpDir()
     */
    var $tmpdir = '/tmp';



    /**
     * Create a new Mbox class instance.
     * After creating it, you should use open().
     *
     * @param string $file  Filename to open.
     * @access public
     */
    function Mail_Mbox($file)
    {
        $this->_file = $file;
    }

    /**
     * Open the mbox file
     *
     * Also, this function will process the Mbox and create a cache
     * that tells each message start and end bytes.
     *
     * @access public
     */
    function open()
    {
        // check if file exists else return pear error
        if (!file_exists($this->_file)) {
            return PEAR::raiseError('Cannot open the mbox file "' . $this->_file . '": file does not exist.');
        }

        // opening the file
        $this->_lastModified = filemtime($this->_file);
        $this->_resource = fopen($this->_file, 'r');
        if (!is_resource($this->_resource)) {
            return PEAR::raiseError('Cannot open the mbox file: maybe without permission.');
        }

        // process the file and get the messages bytes offsets
        $this->_process();

        return true;
    }

    /**
     * Close a Mbox
     *
     * Close the Mbox file opened by open()
     *
     * @return   mixed       true on success, else PEAR_Error
     * @access   public
     */
    function close()
    {
        if (!is_resource($this->_resource)) {
            return PEAR::raiseError('Cannot close the mbox file because it was not open.');
        }

        if (!fclose($this->_resource)) {
            return PEAR::raiseError('Cannot close the mbox, maybe file is being used (?)');
        }

        return true;
    }

    /**
     * Get number of messages in this mbox
     *
     * @return   int                 Number of messages on Mbox (starting on 1,
     *                               0 if no message exists)
     * @access   public
     */
    function size()
    {
        if ($this->_index !== null) {
            return sizeof($this->_index);
        } else {
            return 0;
        }
    }

    /**
     * Get a message from the mbox
     *
     * Note: Message number start from 0.
     *
     * @param    int $message        The number of Message
     * @return   string              Return the message, PEAR_Error on error
     * @access   public
     */
    function get($message)
    {
        // checking if we have bytes locations for this message
        if (!is_array($this->_index[$message])) {
            return PEAR::raiseError('Message does not exist.');
        }

        // getting bytes locations
        $bytesStart = $this->_index[$message][0];
        $bytesEnd = $this->_index[$message][1];

        // a debug feature to show the bytes locations
        if ($this->debug) {
            printf("%08d=%08d<br />", $bytesStart, $bytesEnd);
        }

        // seek to start of message
        if (fseek($this->_resource, $bytesStart) == -1) {
            return PEAR::raiseError('Cannot read message bytes');
        }

        if ($bytesEnd - $bytesStart > 0) {
            // reading and returning message (bytes to read = difference of bytes locations)
            $msg = fread($this->_resource, $bytesEnd - $bytesStart) . "\n";
            return $msg;
        }
    }

    /**
     * Remove a message from Mbox and save it.
     *
     * Note: messages start with 0.
     *
     * @param    int $message        The number of the message to remove, or
     *                               array of message ids to remove
     * @return   mixed               Return true else PEAR_Error
     * @access   public
     */
    function remove($message)
    {
        if ($this->hasBeenModified()) {
            return PEAR::raiseError('File has been modified since loading. Re-open the file.');
        }

        // convert single message to array
        if (!is_array($message)) {
            $message = array($message);
        }

        // checking if we have bytes locations for this message
        foreach ($message as $msg) {
            if (!isset($this->_index[$msg]) || !is_array($this->_index[$msg])) {
                return PEAR::raiseError('Message ' . $msg . 'does not exist.');
            }
        }

        // changing umask for security reasons
        $umaskOld   = umask(077);
        // creating temp file
        $ftempname  = tempnam($this->tmpdir, 'Mail_Mbox');
        // returning to old umask
        umask($umaskOld);

        $ftemp      = fopen($ftempname, 'w');
        if ($ftemp === false) {
            return PEAR::raiseError('Cannot create a temp file "' . $ftempname . '". Cannot handle this error.');
        }

        // writing only undeleted messages 
        $messages = $this->size();

        for ($x = 0; $x < $messages; $x++) {
            if (in_array($x, $message)) {
                continue;
            }

            $messageThis = $this->get($x);
            if (is_string($messageThis)) {
                fwrite($ftemp, $messageThis, strlen($messageThis));
            }
        }

        // closing file
        $this->close();
        fclose($ftemp);

        return $this->_move($ftempname, $this->_file);
    }

    /**
     * Update a message
     *
     * Note: Mail_Mbox auto adds \n\n at end of the message
     *
     * Note: messages start with 0.
     *
     * @param    int $message        The number of Message to updated
     * @param    string $content     The new content of the Message
     * @return   mixed               Return true if all is ok, else PEAR_Error
     * @access   public
     */
    function update($message, $content)
    {
        if ($this->hasBeenModified()) {
            return PEAR::raiseError('File has been modified since loading. Re-open the file.');
        }

        // checking if we have bytes locations for this message
        if (!is_array($this->_index[$message])) {
            return PEAR::raiseError('Message does not exists.');
        }

        // creating temp file
        $ftempname  = tempnam($this->tmpdir, 'Mail_Mbox');
        $ftemp = fopen($ftempname, 'w');
        if ($ftemp === false) {
            return PEAR::raiseError('Cannot create temp file "' . $ftempname . '" . Cannot handle this error.');
        }

        $messages = $this->size();

        for ($x = 0; $x < $messages; $x++) {
            if ($x == $message) {
                $messageThis = $content . "\n\n";
            } else {
                $messageThis = $this->get($x);
            }

            if (is_string($messageThis)) {
                fwrite($ftemp, $messageThis, strlen($messageThis));
            }
        }

        // closing file
        $this->close();
        fclose($ftemp);

        return $this->_move($ftempname, $this->_file);
    }

    /**
     * Insert a message
     *
     * PEAR::Mail_Mbox will insert the message according its offset. 
     * 0 means before the actual message 0. 3 means before the message 3
     * (Remember: message 3 is the forth message). The default is put
     * AFTER the last message (offset = null).
     *
     * Note: PEAR::Mail_Mbox auto adds \n\n at end of the message
     *
     * @param    string $content     The content of the new message
     * @param    int offset          Before the offset. Default: last message (null)
     * @return   mixed               Return true else pear error class
     * @access   public
     */
    function insert($content, $offset = null)
    {
        if ($this->hasBeenModified()) {
            return PEAR::raiseError('File has been modified since loading. Re-open the file.');
        }

        if ($offset === -1) {
            $offset = null;
        }

        // creating temp file
        $ftempname  = tempnam($this->tmpdir, 'Mail_Mbox');
        $ftemp = fopen($ftempname, 'w');
        if ($ftemp === false) {
            return PEAR::raiseError('Cannot create temp file "' . $ftempname . '". Cannot handle this error.');
        }

        // writing only undeleted messages
        $messages = $this->size();
        $content .= "\n\n";

        if ($messages == 0 && $offset !== null) {
            fwrite($ftemp, $content, strlen($content));
        } else {
            for ($x = 0; $x < $messages; $x++)  {
                if ($offset !== null && $x == $offset) {
                    fwrite($ftemp, $content, strlen($content));
                }
                $messageThis = $this->get($x);

                if (is_string($messageThis)) {
                    fwrite($ftemp, $messageThis, strlen($messageThis));
                }
            }
        }

        if ($offset === null) {
            fwrite($ftemp, $content, strlen($content));
        }

        // closing file
        $this->close();
        fclose($ftemp);

        return $this->_move($ftempname, $this->_file);
    }

    /**
     * Copy a file to another
     *
     * Used internally to copy the content of the temp file to the mbox file
     *
     * @parm     string $ftempname   Source file - will be removed
     * @param    string $filename    Output file
     * @access   protected
     */
    function _move($ftempname, $filename)
    {
        // opening ftemp to read
        $ftemp = fopen($ftempname, 'r');

        if ($ftemp === false) {
            return PEAR::raiseError('Cannot open temp file "' . $ftempname . '".');
        }

        // copy from ftemp to fp
        $fp = fopen($filename, 'w');
        if ($fp === false) {
            return PEAR::raiseError('Cannot write on mbox file "' . $filename . '".');
        }

        while (feof($ftemp) != true) {
            $strings = fread($ftemp, 4096);
            if (fwrite($fp, $strings, strlen($strings)) === false) {
                return PEAR::raiseError('Cannot write to file "' . $filename . '".');
            }
        }

        fclose($fp);
        fclose($ftemp);
        unlink($ftempname);

        // open another resource and substitute it to the old one
        $this->_file = $filename;
        return $this->open();
    }

    /**
     * Process the Mbox
     *
     * - Get start bytes and end bytes of each messages
     *
     * @access   protected
     */
    function _process()
    {
        $this->_index = array();

        // sanity check
        if (!is_resource($this->_resource)) {
            return PEAR::raiseError('Resource is not valid. Maybe the file has not be opened?');
        }

        // going to start
        if (fseek($this->_resource, 0) == -1) {
            return PEAR::raiseError('Cannot read mbox');
        }

        // current start byte position
        $start      = 0;
        // last start byte position
        $laststart  = 0;
        // there aren't any message
        $hasmessage = false;

        while ($line = fgets($this->_resource, 4096)) {
            // if line start with "From ", it is a new message
            if (0 === strncmp($line, 'From ', 5)) {
                // save last start byte position
                $laststart  = $start;

                // new start byte position is the start of the line 
                $start      = ftell($this->_resource) - strlen($line);

                // if it is not the first message add message positions
                if ($start > 0) {
                    $this->_index[] = array($laststart, $start - 1);
                } else {
                    // tell that there is really a message on the file
                    $hasmessage = true;
                }
            }
        }

        // if there are just one message, or if it's the last one,
        // add it to messages positions
        if (($start == 0 && $hasmessage === true) || ($start > 0)) {
            $this->_index[] = array($start, ftell($this->_resource));
        }
    }

    /**
     * Checks if the file was modified since it has been loaded.
     * If this is true, the file needs to be re-opened.
     *
     * @return boolean  True if it has been modified.
     * @access public
     */
    function hasBeenModified()
    {
        return filemtime($this->_file) > $this->_lastModified;
    }



    /*
     * Dumb getter and setter
     */



    /**
     * Set the directory for temporary files.
     * @see Mail_Mbox::$tmpdir
     *
     * @param string $tmpdir    The new temporary directory
     */
    function setTmpDir($tmpdir)
    {
        $this->tmpdir = $tmpdir;
    }

    /**
     * Returns the temporary directory
     *
     * @return string   The temporary directory
     */
    function getTmpDir()
    {
        return $this->tmpdir;
    }

    /**
     * Set the debug flag
     * @see Mail_Mbox::$debug
     *
     * @param boolean $debug    If debug is on or off
     */
    function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * Returns the debug flag setting
     *
     * @return boolean  If debug is enabled.
     */
    function getDebug()
    {
        return $this->debug;
    }
}
?>