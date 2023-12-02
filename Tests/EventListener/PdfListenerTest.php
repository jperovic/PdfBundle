<?php

namespace Ps\PdfBundle\Tests\EventListener;

use Doctrine\Common\Annotations\Reader;
use PHPPdf\Cache\Cache;
use PHPPdf\Core\Facade;
use PHPPdf\Core\FacadeBuilder;
use PHPPdf\Parser\Exception\ParseException;
use PHPUnit_Framework_TestCase;
use Ps\PdfBundle\Annotation\Pdf;
use Ps\PdfBundle\EventListener\PdfListener;
use Ps\PdfBundle\Reflection\Factory;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;

class PdfListenerTest extends PHPUnit_Framework_TestCase
{
    private $pdfFacadeBuilder;
    private $pdfFacade;
    private $annotationReader;
    private $listener;
    private $controllerEvent;
    private $request;
    private $requestAttributes;
    private $reflactionFactory;
    private $templatingEngine;
    private $cache;
    private $kernel;

    public function setUp():void
    {
        $this->pdfFacadeBuilder = $this->getMockBuilder(FacadeBuilder::class)
                                       ->disableOriginalConstructor()
                                       ->setMethods(array('build', 'setDocumentParserType'))
                                       ->getMock();
        
        $this->pdfFacade = $this->getMockBuilder(Facade::class)
                                ->disableOriginalConstructor()
                                ->setMethods(array('render'))
                                ->getMock();

        $this->templatingEngine = $this->getMockBuilder(Environment::class)
                                       ->disableOriginalConstructor()
                                       ->setMethods(array('render', 'supports', 'exists'))
                                       ->getMock();
        
        $this->reflactionFactory = $this->getMockBuilder(Factory::class)
                                        ->setMethods(array('createMethod'))
                                        ->getMock();
        $this->annotationReader = $this->getMockBuilder(Reader::class)
                                       ->setMethods(array('getMethodAnnotations', 'getMethodAnnotation', 'getClassAnnotations', 'getClassAnnotation', 'getPropertyAnnotations', 'getPropertyAnnotation'))
                                       ->getMock();
                                       
        $this->cache = $this->getMockBuilder(Cache::class)->getMock();

        $this->listener = new PdfListener(
            $this->pdfFacadeBuilder,
            $this->annotationReader,
            $this->reflactionFactory,
            $this->templatingEngine,
            $this->cache
        );
        
        $this->request = $this->getMockBuilder(Request::class)
                              ->setMethods(array('get'))
                              ->getMock();
        $this->requestAttributes = $this->getMockBuilder('stdClass')
                                        ->setMethods(array('set', 'get'))
                                        ->getMock();
                                        
        $this->request->attributes = $this->requestAttributes;

        /** @noinspection ClassMockingCorrectnessInspection */
        /** @noinspection PhpUnitInvalidMockingEntityInspection */
        $this->controllerEvent = $this->getMockBuilder(ControllerEvent::class)
                                      ->setMethods(array('setController', 'getController', 'getRequest'))
                                      ->disableOriginalConstructor()
                                      ->getMock();
                            
        $this->controllerEvent
                    ->method('getRequest')
                    ->willReturn($this->request);

        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    /**
     * @test
     * @dataProvider annotationProvider
     * @throws ReflectionException
     */
    public function setAnnotationObjectToRequestIfRequestFormatIsPdfAndAnnotationExists($annotation, $format, $shouldControllerBeenSet): void
    {
        $objectStub = new FileLocator();
        $controllerStub = array($objectStub, 'locate');
        $methodStub = new ReflectionMethod($controllerStub[0], $controllerStub[1]);

        $this->request->expects($this->once())
                      ->method('get')
                      ->with('_format')
                      ->willReturn($format);
        
        $this->controllerEvent
                    ->method('getController')
                    ->willReturn($controllerStub);
        
        if($format === 'pdf')
        {
            $this->reflactionFactory->expects($this->once())
                                    ->method('createMethod')
                                    ->with($controllerStub[0], $controllerStub[1])
                                    ->willReturn($methodStub);
            
            $this->annotationReader->expects($this->once())
                                   ->method('getMethodAnnotation')
                                   ->with($methodStub, Pdf::class)
                                   ->willReturn($annotation);
        }
        else
        {
            $this->reflactionFactory->expects($this->never())
                                    ->method('createMethod');
            
            $this->annotationReader->expects($this->never())
                                   ->method('getMethodAnnotation');
        }
                    
        if($shouldControllerBeenSet)
        {
            $this->requestAttributes->expects($this->once())
                                    ->method('set')
                                    ->with('_pdf', $annotation);
        }
        else
        {
            $this->requestAttributes->expects($this->never())
                                    ->method('set');
        }
                    
        $this->listener->onKernelController($this->controllerEvent);
    }
    
    public function annotationProvider(): array
    {
        $annotation = new Pdf(array());
        
        return array(
            array($annotation, 'pdf', true),
            array(null, 'pdf', false),
            array($annotation, 'html', false),
        );
    }
    
    /**
     * @test
     */
    public function donotInvokePdfRenderingOnViewEventWhenResponseStatusIsError(): void
    {
        $annotation = new Pdf(array());
        $this->requestAttributes->expects($this->once())
                                ->method('get')
                                ->with('_pdf')
                                ->willReturn($annotation);

        $responseStub = new Response();
        $responseStub->setStatusCode(300);
        $event = new ResponseEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, $responseStub);
                        
        $this->pdfFacadeBuilder->expects($this->never())
                               ->method('build');

        $this->listener->onKernelResponse($event);
    }
    
    /**
     * @test
     * @dataProvider booleanPairProvider
     */
    public function invokePdfRenderingOnViewEvent($enableCache, $freshCache): void
    {
        $annotation = new Pdf(array('enableCache' => $enableCache));
        $this->requestAttributes->expects($this->once())
                                ->method('get')
                                ->with('_pdf')
                                ->willReturn($annotation);
                                
        $contentStub = 'stub';
        $responseContent = 'controller result stub';
        $responseStub = new Response($responseContent);

        if($enableCache)
        {
            $cacheKey = md5($responseContent);
            $this->cache->expects($this->once())
                        ->method('test')
                        ->with($cacheKey)
                        ->willReturn($freshCache);
            
            if($freshCache)
            {
                $this->cache->expects($this->once())
                            ->method('load')
                            ->with($cacheKey)
                            ->willReturn($contentStub);
            }
            else
            {
                $this->cache->expects($this->never())
                            ->method('load');
                
                $this->expectPdfFacadeBuilding($annotation);
                
                $this->pdfFacade->expects($this->once())
                                ->method('render')
                                ->with($responseContent)
                                ->willReturn($contentStub);
                                
                $this->cache->expects($this->once())
                            ->method('save')
                            ->with($contentStub, $cacheKey);
            }
        }
        else
        {
            foreach(array('test', 'load', 'save') as $method)
            {
                $this->cache->expects($this->never())
                            ->method($method);
            }
            
            $this->expectPdfFacadeBuilding($annotation);
            
            $this->pdfFacade->expects($this->once())
                            ->method('render')
                            ->with($responseContent)
                            ->willReturn($contentStub);
        }
        
        $event = new ResponseEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, $responseStub);
                        
        $this->listener->onKernelResponse($event);
        
        $response = $event->getResponse();
        
        $this->assertEquals($contentStub, $response->getContent());
    }
    
    public function booleanPairProvider(): array
    {
        return array(
            array(false, false),
            array(true, true),
            array(true, false),
        );
    }
    
    private function expectPdfFacadeBuilding(Pdf $annotation): void
    {
        $this->pdfFacadeBuilder->expects($this->once())
                               ->method('setDocumentParserType')
                               ->with($annotation->documentParserType)
                               ->willReturn($this->pdfFacadeBuilder);
        $this->pdfFacadeBuilder->expects($this->once())
                               ->method('build')
                               ->willReturn($this->pdfFacade);
    }
    
    /**
     * @test
     */
    public function setResponseContentTypeAndRequestFormatOnException(): void
    {
        $annotation = new Pdf(array('enableCache' => false));
        $this->requestAttributes->expects($this->once())
                                ->method('get')
                                ->with('_pdf')
                                ->willReturn($annotation);
        
        $this->expectPdfFacadeBuilding($annotation);

        $exception = new ParseException();
                               
        $this->pdfFacade->expects($this->once())
                        ->method('render')
                        ->will($this->throwException($exception));

        $responseStub = new Response();
        $event = new ResponseEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, $responseStub);
                        
        try
        {
            $this->listener->onKernelResponse($event);
            $this->fail('exception expected');
        }
        catch(ParseException $e)
        {
            $this->assertEquals('text/html', $responseStub->headers->get('content-type'));
            $this->assertEquals('html', $this->request->getRequestFormat('pdf'));
        }
    }
    
    /**
     * @test
     */
    public function useStylesheetFromAnnotation(): void
    {
        $stylesheetPath = 'some path';
        
        $annotation = new Pdf(array('stylesheet' => $stylesheetPath, 'enableCache' => false));
        $this->requestAttributes->expects($this->once())
                                ->method('get')
                                ->with('_pdf')
                                ->willReturn($annotation);
                                
        $stylesheetContent = 'stylesheet content';
        
        $this->templatingEngine->expects($this->once())
                               ->method('render')
                               ->with($stylesheetPath)
                               ->willReturn($stylesheetContent);
        
        $this->pdfFacadeBuilder->expects($this->once())
                               ->method('setDocumentParserType')
                               ->with($annotation->documentParserType)
                               ->willReturn($this->pdfFacadeBuilder);
        $this->pdfFacadeBuilder->expects($this->once())
                               ->method('build')
                               ->willReturn($this->pdfFacade);
                               
        $this->pdfFacade->expects($this->once())
                        ->method('render')
                        ->with($this->anything(), $stylesheetContent);

        $event = new ResponseEvent($this->kernel, $this->request, HttpKernelInterface::MAIN_REQUEST, new Response());
        $this->listener->onKernelResponse($event);
    }
    
    /**
     * @test
     */
    public function breakInvocationIfControllerIsEmpty(): void
    {
        $this->request->expects($this->once())
                      ->method('get')
                      ->with('_format')
                      ->willReturn('pdf');
        
        $this->controllerEvent->expects($this->once())
                    ->method('getController')
                    ->willReturn(function(){});
                    
        $this->reflactionFactory->expects($this->never())
                                ->method('createMethod');
                                
        $this->listener->onKernelController($this->controllerEvent);
    }
}

    //class FilterResponseEventStub extends ResponseEvent
    //{
    //    private $request;
    //    private $response;
    //
    //    public function __construct(HttpKernelInterface $kernel, Request $request, Response $response)
    //    {
    //        parent::__construct($kernel, $request, HttpKernelInterface::MASTER_REQUEST, $response);
    //
    //        $this->request = $request;
    //        $this->response = $response;
    //    }
    //
    //    public function getResponse()
    //    {
    //        return $this->response;
    //    }
    //
    //    public function setResponse(Response $response)
    //    {
    //        $this->response = $response;
    //    }
    //
    //	public function getRequest()
    //    {
    //        return $this->request;
    //    }
    //}