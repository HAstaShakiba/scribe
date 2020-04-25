<?php

namespace Knuckles\Scribe\Extracting\Strategies\Responses;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Knuckles\Scribe\Tools\AnnotationParser;
use League\Fractal\Resource\Collection;
use Knuckles\Scribe\Extracting\RouteDocBlocker;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Knuckles\Scribe\Tools\Flags;
use Knuckles\Scribe\Tools\Utils;
use Mpociot\Reflection\DocBlock;
use Mpociot\Reflection\DocBlock\Tag;
use ReflectionClass;
use ReflectionFunctionAbstract;

/**
 * Parse an Eloquent API resource response from the docblock ( @apiResource || @apiResourcecollection ).
 */
class UseApiResourceTags extends Strategy
{
    /**
     * @param Route $route
     * @param ReflectionClass $controller
     * @param ReflectionFunctionAbstract $method
     * @param array $rulesToApply
     * @param array $context
     *
     * @return array|null
     * @throws Exception
     *
     */
    public function __invoke(Route $route, ReflectionClass $controller, ReflectionFunctionAbstract $method, array $rulesToApply, array $context = [])
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($route);
        /** @var DocBlock $methodDocBlock */
        $methodDocBlock = $docBlocks['method'];

        try {
            return $this->getApiResourceResponse($methodDocBlock->getTags(), $route);

        } catch (Exception $e) {
            clara('knuckleswtf/scribe')->warn('Exception thrown when fetching Eloquent API resource response for [' . implode(',', $route->methods) . "] {$route->uri}.");
            if (Flags::$shouldBeVerbose) {
                Utils::dumpException($e);
            } else {
                clara('knuckleswtf/scribe')->warn("Run this again with the --verbose flag to see the exception.");
            }

            return null;
        }
    }

    /**
     * Get a response from the @apiResource/@apiResourceCollection and @apiResourceModel tags.
     *
     * @param array $tags
     *
     * @return array|null
     */
    public function getApiResourceResponse(array $tags)
    {
        if (empty($apiResourceTag = $this->getApiResourceTag($tags))) {
            return null;
        }

        list($statusCode, $apiResourceClass) = $this->getStatusCodeAndApiResourceClass($apiResourceTag);
        [$model, $factoryStates] = $this->getClassToBeTransformed($tags);
        $modelInstance = $this->instantiateApiResourceModel($model, $factoryStates);

        try {
            $resource = new $apiResourceClass($modelInstance);
        } catch (Exception $e) {
            // If it is a ResourceCollection class, it might throw an error
            // when trying to instantiate with something other than a collection
            $resource = new $apiResourceClass(collect([$modelInstance]));
        }
        if (strtolower($apiResourceTag->getName()) == 'apiresourcecollection') {
            // Collections can either use the regular JsonResource class (via `::collection()`,
            // or a ResourceCollection (via `new`)
            // See https://laravel.com/docs/5.8/eloquent-resources
            $models = [$modelInstance, $this->instantiateApiResourceModel($model, $factoryStates)];
            $resource = $resource instanceof ResourceCollection
                ? new $apiResourceClass(collect($models))
                : $apiResourceClass::collection(collect($models));
        }

        /** @var Response $response */
        $response = $resource->toResponse(app(Request::class));

        return [
            [
                'status' => $statusCode ?: $response->getStatusCode(),
                'content' => $response->getContent(),
            ],
        ];
    }

    /**
     * @param Tag $tag
     *
     * @return array
     */
    private function getStatusCodeAndApiResourceClass($tag): array
    {
        $content = $tag->getContent();
        preg_match('/^(\d{3})?\s?([\s\S]*)$/', $content, $result);
        $status = $result[1] ?: 0;
        $apiResourceClass = $result[2];

        return [$status, $apiResourceClass];
    }

    private function getClassToBeTransformed(array $tags): array
    {
        $modelTag = Arr::first(array_filter($tags, function ($tag) {
            return ($tag instanceof Tag) && strtolower($tag->getName()) == 'apiresourcemodel';
        }));

        $type = null;
        $states = [];
        if ($modelTag) {
            ['content' => $type, 'attributes' => $attributes] = AnnotationParser::parseIntoContentAndAttributes($modelTag->getContent(), ['states']);
            $states = explode(',', $attributes['states'] ?? '');
        }

        if (empty($type)) {
            throw new Exception("Couldn't detect an Eloquent API resource model from your docblock. Did you remember to specify a model using @apiResourceModel?");
        }

        return [$type, $states];
    }

    /**
     * @param string $type
     *
     * @param array $factoryStates
     *
     * @return Model|object
     */
    protected function instantiateApiResourceModel(string $type, array $factoryStates = [])
    {
        try {
            // Try Eloquent model factory

            // Factories are usually defined without the leading \ in the class name,
            // but the user might write it that way in a comment. Let's be safe.
            $type = ltrim($type, '\\');

            $factory = factory($type);
            if (count($factoryStates)) {
                $factory->states($factoryStates);
            }
            return $factory->make();
        } catch (Exception $e) {
            if (Flags::$shouldBeVerbose) {
                clara('knuckleswtf/scribe')->warn("Eloquent model factory failed to instantiate {$type}; trying to fetch from database.");
            }

            $instance = new $type();
            if ($instance instanceof \Illuminate\Database\Eloquent\Model) {
                try {
                    // we can't use a factory but can try to get one from the database
                    $firstInstance = $type::first();
                    if ($firstInstance) {
                        return $firstInstance;
                    }
                } catch (Exception $e) {
                    // okay, we'll stick with `new`
                    if (Flags::$shouldBeVerbose) {
                        clara('knuckleswtf/scribe')->warn("Failed to fetch first {$type} from database; using `new` to instantiate.");
                    }
                }
            }
        }

        return $instance;
    }

    /**
     * @param array $tags
     *
     * @return Tag|null
     */
    private function getApiResourceTag(array $tags)
    {
        $apiResourceTags = array_values(
            array_filter($tags, function ($tag) {
                return ($tag instanceof Tag) && in_array(strtolower($tag->getName()), ['apiresource', 'apiresourcecollection']);
            })
        );

        return Arr::first($apiResourceTags);
    }
}
