<?php namespace Vmod\Circularity;

class Plugin extends \System\Classes\PluginBase
{
    public function boot()
    {
        // Keep API validation ahead of the host's CMS error-page renderer.
        app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(
            function (\Illuminate\Validation\ValidationException $exception, \Illuminate\Http\Request $request) {
                if ($request->is('api/circularity/v1/*')) {
                    return response()->json(['message' => 'Invalid circularity data.', 'errors' => $exception->errors()], 422);
                }
            }
        );
    }

    public function pluginDetails()
    {
        return ['name' => 'Circularity', 'description' => 'Food court measurement API', 'author' => 'Vmod', 'icon' => 'icon-recycle'];
    }
}
