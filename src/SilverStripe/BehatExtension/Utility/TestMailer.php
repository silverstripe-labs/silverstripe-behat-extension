<?php

namespace SilverStripe\BehatExtension\Utility;

/**
 * Same principle as core TestMailer class,
 * but saves emails in {@link TestSessionEnvironment}
 * to share the state between PHP calls (CLI vs. browser).
 */
class TestMailer extends \Mailer
{

    /**
     * @var TestSessionEnvironment
     */
    protected $testSessionEnvironment;

    public function __construct()
    {
        $this->testSessionEnvironment = \Injector::inst()->get('TestSessionEnvironment');
    }

    /**
     * Send a plain-text email.
     * TestMailer will merely record that the email was asked to be sent, without sending anything.
     */
    public function sendPlain($to, $from, $subject, $plainContent, $attachedFiles = false, $customHeaders = false)
    {
        $this->saveEmail(array(
            'Type' => 'plain',
            'To' => $to,
            'From' => $from,
            'Subject' => $subject,
            'Content' => $plainContent,
            'PlainContent' => $plainContent,
            'AttachedFiles' => $attachedFiles,
            'CustomHeaders' => $customHeaders,
        ));

        return true;
    }
    
    /**
     * Send a multi-part HTML email
     * TestMailer will merely record that the email was asked to be sent, without sending anything.
     */
    public function sendHTML(
        $to,
        $from,
        $subject,
        $htmlContent,
        $attachedFiles = false,
        $customHeaders = false,
        $plainContent = false,
        $inlineImages = false
    ) {
    
        $this->saveEmail(array(
            'Type' => 'html',
            'To' => $to,
            'From' => $from,
            'Subject' => $subject,
            'Content' => $htmlContent,
            'PlainContent' => $plainContent,
            'AttachedFiles' => $attachedFiles,
            'CustomHeaders' => $customHeaders,
        ));

        return true;
    }
    
    /**
     * Clear the log of emails sent
     */
    public function clearEmails()
    {
        $state = $this->testSessionEnvironment->getState();
        if (isset($state->emails)) {
            unset($state->emails);
        }
        $this->testSessionEnvironment->applyState($state);
    }

    /**
     * Search for an email that was sent.
     * All of the parameters can either be a string, or, if they start with "/", a PREG-compatible regular expression.
     *
     * @param $to
     * @param $from
     * @param $subject
     * @param $content
     * @return array Contains the keys: 'type', 'to', 'from', 'subject', 'content', 'plainContent', 'attachedFiles',
     *               'customHeaders', 'htmlContent', 'inlineImages'
     */
    public function findEmail($to = null, $from = null, $subject = null, $content = null)
    {
        $matches = $this->findEmails($to, $from, $subject, $content);
                //got the count of matches emails
                $emailCount = count($matches);
                //get the last(latest) one
        return $matches ? $matches[$emailCount-1] : null;
    }

    /**
     * Search for all emails.
     * All of the parameters can either be a string, or, if they start with "/", a PREG-compatible regular expression.
     *
     * @param $to
     * @param $from
     * @param $subject
     * @param $content
     * @return array Contains the keys: 'type', 'to', 'from', 'subject', 'content', 'plainContent', 'attachedFiles',
     *               'customHeaders', 'htmlContent', 'inlineImages'
     */
    public function findEmails($to = null, $from = null, $subject = null, $content = null)
    {
        $matches = array();
        $args = func_get_args();
        $state = $this->testSessionEnvironment->getState();
        $emails = isset($state->emails) ? $state->emails : array();
        foreach ($emails as $email) {
            $matched = true;

            foreach (array('To', 'From', 'Subject', 'Content') as $i => $field) {
                if (!isset($email->$field)) {
                    continue;
                }
                $value = (isset($args[$i])) ? $args[$i] : null;
                if ($value) {
                    if ($value[0] == '/') {
                        $matched = preg_match($value, $email->$field);
                    } else {
                        $matched = ($value == $email->$field);
                    }
                    if (!$matched) {
                        break;
                    }
                }
            }
            if ($matched) {
                $matches[] = $email;
            }
        }

        return $matches;
    }

    protected function saveEmail($data)
    {
        $state = $this->testSessionEnvironment->getState();
        if (!isset($state->emails)) {
            $state->emails = array();
        }
        $state->emails[] = array_filter($data);
        $this->testSessionEnvironment->applyState($state);
    }
}
