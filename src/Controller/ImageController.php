<?php

namespace App\Controller;
ini_set('memory_limit', '256M');

use GuzzleHttp\Client;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\Point;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class ImageController extends AbstractController
{
    /**
     * @Route("/", name="image_upload", methods={"GET", "POST"})
     */
    public function upload(Request $request): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->handleAjaxRequest($request);
        }

        $savedImages = $this->getSavedImages();
        return $this->render('image_upload.html.twig', [
            'images' => $savedImages,
        ]);
    }

    private function handleAjaxRequest(Request $request): JsonResponse
    {
        set_time_limit(600); //time limit for execution for big number of images

        $images = []; //array to store imgs
        $uploadDir = $this->getParameter('kernel.project_dir').'/public/uploads'; //directory for imgs
        $url = $request->request->get('url'); //target url
        $minWidth = (int)$request->request->get('minWidth', 0); //minimal width for the images
        $minHeight = (int)$request->request->get('minHeight', 0); //min height for the images
        $text = $request->request->get('text'); //custom user text

        $client = new Client(); //guzzle instance
        try {
            $response = $client->get($url); //get request
            $htmlContent = $response->getBody()->getContents(); //gets content
            //finds img tags and gets imgs url, writing it to the array
            preg_match_all('/<img[^>]+src=["\']?([^"\'>\s]+)["\']?/i', $htmlContent, $matches);
            $imageUrls = $matches[1];

            $parsedUrl = parse_url($url);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

            $batchSize = 10;
            $batches = array_chunk($imageUrls, $batchSize);

            foreach ($batches as $batch) {
                foreach ($batch as $imageUrl) {
                    try {
                        if (strpos($imageUrl, 'http') !== 0) {
                            $imageUrl = $baseUrl . ($imageUrl[0] === '/' ? '' : '/') . $imageUrl;
                        }

                        $imageResponse = $client->get($imageUrl, ['stream' => true]);
                        $imageData = $imageResponse->getBody()->getContents();

                        $imagine = new Imagine();
                        $image = $imagine->load($imageData);

                        $size = $image->getSize();
                        if ($size->getWidth() >= $minWidth && $size->getHeight() >= $minHeight) {
                            $resizedImage = $image->resize(new Box($size->getWidth() * (200 / $size->getHeight()), 200));
                            $croppedImage = $resizedImage->crop(new Point(0, 0), new Box(200, 200));

                            if (!empty($text)) {
                                $font = $imagine->font('../public/fonts/Arial.ttf', 12, $croppedImage->palette()->color('#000000'));
                                $croppedImage->draw()->text($text, $font, new Point(10, 10));
                            }
                            
                            //imgs namme and directory
                            $fileName = uniqid().'.jpg';
                            $croppedImage->save($uploadDir.'/'.$fileName);
                            $images[] = '/uploads/'.$fileName;
                        }
                    } catch (\Exception $e) {
                        error_log('Processing error: '.$e->getMessage().' URL: '.$imageUrl);
                    }
                }
                gc_collect_cycles(); //clears memory
            }
        } catch (\Exception $e) {
            error_log('Failed to parse the URL: '.$e->getMessage().' URL: ' . $url);
            return new JsonResponse(['error' => 'Failed to parse the URL: '.$e->getMessage()], 400);
        }

        return new JsonResponse([
            'images' => $images,
            'totalImages' => count($imageUrls),
            'processedImages' => count($images),
        ]);
    }

    private function getSavedImages(): array
    {
        $uploadDir = $this->getParameter('kernel.project_dir').'/public/uploads';
        return array_map(function ($file) {
            return '/uploads/'.basename($file);
        }, glob($uploadDir.'/*'));
    }
}