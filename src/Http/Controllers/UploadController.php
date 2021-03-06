<?php
/**
 * Created by PhpStorm.
 * User: leodanielstuder
 * Date: 01.06.19
 * Time: 14:35
 */

namespace le0daniel\Laravel\ResumableJs\Http\Controllers;

use Faker\Provider\File;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use le0daniel\Laravel\ResumableJs\Contracts\UploadHandler;
use le0daniel\Laravel\ResumableJs\Models\FileUpload;
use le0daniel\Laravel\ResumableJs\Upload\Manager;

class UploadController extends BaseController
{
    use ValidatesRequests;

    /**
     * @var UploadHandler
     */
    protected $handler;

    /**
     * UploadController constructor.
     * @param Request $request
     * @throws \Exception
     */
    public function __construct(Request $request)
    {
        if (!$this->hasHandlers()) {
            throw new \Exception('No upload handlers defined.');
        }

        if ($request->route()->uri !== 'upload/init') {
            return;
        }

        // Add the handler middleware
        $this->handler = $this->getHandler($request->get('handler', ''));
        if ($middleware = $this->handler->middleware()) {
            $this->middleware($middleware);
        }
    }

    /**
     * Check if handlers are defined
     *
     * @return bool
     */
    protected function hasHandlers(): bool
    {
        return !empty(config('resumablejs.handlers', false));
    }

    /**
     * Get the handler by name
     *
     * @param string $handlerName
     * @return UploadHandler
     */
    protected function getHandler(string $handlerName): UploadHandler
    {
        $handlers = config('resumablejs.handlers');

        if (!array_key_exists($handlerName, $handlers)) {
            abort(404,'Handler not found.');
        }

        return App::make($handlers[$handlerName]);
    }

    /**
     * Generates a unique token
     * @return string
     */
    protected function generateUniqueToken(): string
    {
        return strtolower(Str::random(64));
    }

    /**
     * Get the uncomplete file upload
     *
     * @param string $token
     * @return FileUpload
     */
    protected function getFileUploadOrFail(string $token): FileUpload
    {
        return FileUpload::where('token', '=', $token)
            ->where('is_complete', '=', 0)
            ->firstOrFail();
    }

    /**
     * Makes sure a handler was found and it s middlewares enforced
     */
    protected function hasHandlerOrFail()
    {
        // Make sure a handler is set
        if (!isset($this->handler)) {
            abort(404,'Handler not found');
        }
    }

    /**
     * @param Request $request
     * @return array
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Throwable
     */
    public function init(Request $request)
    {
        $this->hasHandlerOrFail();

        // Validate the request
        $this->validate($request, [
            'size' => 'required|integer|min:1',
            'name' => 'required|string',
            'type' => 'required|string',
            'payload' => 'nullable|array'
        ]);

        // Extract all data
        $name = basename((string)$request->get('name'));
        $size = intval($request->get('size'));
        $payload = $request->get('payload', []);
        $fileUpload = new FileUpload([
            'name' => $name,
            'size' => $size,
            'type' => (string)$request->get('type'),
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'chunks' => ceil($size / config('resumablejs.chunk_size')),
        ]);

        try {
            $this->handler->validateOrFail($fileUpload, $payload, $request);
        } catch (\Exception $e) {
            abort(422, 'Invalid File');
        }

        // Add the payload
        $fileUpload->payload = $this->handler->payload($fileUpload, $payload, $request);
        $fileUpload->token = $this->generateUniqueToken();
        $fileUpload->handler = get_class($this->handler);
        $fileUpload->saveOrFail();

        return [
            'success' => true,
            'token' => $fileUpload->token
        ];
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @throws \Illuminate\Validation\ValidationException
     */
    public function check(Request $request, Manager $manager)
    {
        $this->validate($request, [
            'token' => 'required|string|size:64',
            'resumableChunkNumber' => 'required|integer|min:1'
        ]);

        $complete = $manager->hasCompletedChunk(
            $this->getFileUploadOrFail($request->get('token')),
            $request->get('resumableChunkNumber')
        );

        return response('', $complete ? 200 : 204);
    }

    /**
     * @param Request $request
     * @param Manager $manager
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     * @throws \Illuminate\Validation\ValidationException|\Exception
     */
    public function upload(Request $request, Manager $manager)
    {
        $this->validate($request, [
            'token' => 'required|string|size:64',
            'resumableChunkNumber' => 'required|integer|min:1',
            'file' => 'required|file|max:' . config('resumablejs.chunk_size')
        ]);

        // Upload a chunk
        $manager->handleChunk(
            $this->getFileUploadOrFail($request->get('token')),
            $request->get('resumableChunkNumber'),
            $request->file('file')
        );

        return response('', 200);
    }

    /**
     * @param Request $request
     * @param Manager $manager
     * @return array
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Throwable
     */
    public function complete(Request $request, Manager $manager)
    {
        $this->validate($request, [
            'token' => 'required|string|size:64',
        ]);

        /* Complete the upload */
        return $manager->process(
            $this->getFileUploadOrFail($request->get('token'))
        );
    }

}