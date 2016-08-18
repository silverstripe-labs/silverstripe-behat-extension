<?php
namespace SilverStripe\BehatExtension\Tests;

use SilverStripe\BehatExtension\Context\SilverStripeContext;
use Behat\Mink\Mink;

class SilverStripeContextTest extends \PHPUnit_Framework_TestCase
{

    protected $backupGlobals = false;

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot find 'region_map' in the behat.yml
     */
    public function testGetRegionObjThrowsExceptionOnUnknownSelector()
    {
        $context = $this->getContextMock();
        $context->getRegionObj('.unknown');
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Cannot find the specified region in the behat.yml
     */
    public function testGetRegionObjThrowsExceptionOnUnknownRegion()
    {
        $context = $this->getContextMock();
        $context->setRegionMap(array('MyRegion' => '.my-region'));
        $context->getRegionObj('.unknown');
    }

    public function testGetRegionObjFindsBySelector()
    {
        $context = $this->getContextMock();
        $context->getSession()->getPage()
            ->expects($this->any())
            ->method('find')
            ->will($this->returnValue($this->getElementMock()));
        $obj = $context->getRegionObj('.some-selector');
        $this->assertNotNull($obj);
    }

    public function testGetRegionObjFindsByRegion()
    {
        $context = $this->getContextMock();
        $el = $this->getElementMock();
        $context->getSession()->getPage()
            ->expects($this->any())
            ->method('find')
            ->will($this->returnCallback(function ($type, $selector) use ($el) {
                return ($selector == '.my-region') ? $el : null;
            }));
        $context->setRegionMap(array('MyRegion' => '.my-asdf'));
        $obj = $context->getRegionObj('.my-region');
        $this->assertNotNull($obj);
    }

    protected function getContextMock()
    {
        $pageMock = $this->getMockBuilder('Behat\Mink\Element\DocumentElement')
            ->disableOriginalConstructor()
            ->setMethods(array('find'))
            ->getMock();
        $sessionMock = $this->getMockBuilder('Behat\Mink\Session')
            ->setConstructorArgs(array(
                $this->getMock('Behat\Mink\Driver\DriverInterface'),
                $this->getMock('Behat\Mink\Selector\SelectorsHandler')
            ))
            ->setMethods(array('getPage'))
            ->getMock();
        $sessionMock->expects($this->any())
            ->method('getPage')
            ->will($this->returnValue($pageMock));
        $mink = new Mink(array('default' => $sessionMock));
        $mink->setDefaultSessionName('default');

        $context = new SilverStripeContext(array());
        $context->setMink($mink);

        return $context;
    }

    protected function getElementMock()
    {
        return $this->getMockBuilder('Behat\Mink\Element\Element')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
