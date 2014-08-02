<?php

namespace SilverStripe\BehatExtension\Tests;

use Behat\Mink\Element\DocumentElement;
use Behat\Mink\Selector\SelectorsHandler;
use Behat\Mink\Session;
use Behat\Mink\Mink;
use Behat\Mink\Driver\DriverInterface;
use Behat\Mink\Element\Element;
use SilverStripe\BehatExtension\Tests\SilverStripeContextTest\FeatureContext;

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

    /**
     * @return FeatureContext
     */
    protected function getContextMock()
    {
        $pageMock = $this->getMockBuilder(DocumentElement::class)
            ->disableOriginalConstructor()
            ->setMethods(array('find'))
            ->getMock();
        $sessionMock = $this->getMockBuilder(Session::class)
            ->setConstructorArgs(array(
                $this->getMockBuilder(DriverInterface::class)->getMock(),
                $this->getMockBuilder(SelectorsHandler::class)->getMock()
            ))
            ->setMethods(array('getPage'))
            ->getMock();
        $sessionMock->expects($this->any())
            ->method('getPage')
            ->will($this->returnValue($pageMock));
        $mink = new Mink(array('default' => $sessionMock));
        $mink->setDefaultSessionName('default');

        $context = new FeatureContext(array());
        $context->setMink($mink);

        return $context;
    }

    /**
     * @return Element|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function getElementMock()
    {
        return $this->getMockBuilder(Element::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
