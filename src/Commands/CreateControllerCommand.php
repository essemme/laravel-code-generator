<?php

namespace CrestApps\CodeGenerator\Commands;

use Illuminate\Console\Command;
use CrestApps\CodeGenerator\Traits\CommonCommand;
use CrestApps\CodeGenerator\Support\Config;
use CrestApps\CodeGenerator\Traits\GeneratorReplacers;
use CrestApps\CodeGenerator\Support\Helpers;
use CrestApps\CodeGenerator\Models\ForeignRelationship;

class CreateControllerCommand extends Command
{
    use CommonCommand,  GeneratorReplacers;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create:controller
                            {controller-name : The name of the controler.}
                            {--model-name= : The model name that this controller will represent.}
                            {--controller-directory= : The directory where the controller should be created under.}
                            {--model-directory= : The path where the model should be created under.}
                            {--views-directory= : The path where the views should be created under.}
                            {--fields= : Fields to use for creating the validation rules.}
                            {--fields-file= : File name to import fields from.}
                            {--routes-prefix= : Prefix of the route group.}
                            {--models-per-page=25 : The amount of models per page for index pages.}
                            {--lang-file-name= : The languages file name to put the labels in.}
                            {--with-form-request : This will extract the validation into a request form class.}
                            {--with-auth : Generate the controller with Laravel auth middlewear. }
                            {--template-name= : The template name to use when generating the code.}
                            {--controller-extends=Http\Controllers\Controller : The base controller to be extend.}
                            {--force : This option will override the controller if one already exists.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new controller.';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Controller';

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return $this->getStubByName('controller', $this->getTemplateName());
    }

    /**
     * Build the model class with the given name.
     *
     * @param  string  $name
     *
     * @return string
     */
    public function handle()
    {
        $input = $this->getCommandInput();
        $destenationFile = $this->getDestenationFile($input->controllerName);

        if ($this->alreadyExists($destenationFile)) {
            $this->error('The controller already exists!');

            return false;
        }

        $requestName = 'Request';
        $requestNameSpace = 'Illuminate\Http\Request';

        if ($input->formRequest) {
            $requestName = $input->formRequestName;
            $requestNameSpace = $this->getRequestsNamespace($requestName);
            $this->makeFormRequest($input);
        }
        
        $fields = $this->getFields($input->fields, $input->langFile, $input->fieldsFile);
        $viewVariablesForIndex = $this->getCompactVariablesFor($fields, $this->getModelPluralName($input->modelName), 'index');
        $viewVariablesForShow = $this->getCompactVariablesFor($fields, $this->getModelName($input->modelName), 'show');
        $viewVariablesForEdit = $this->getCompactVariablesFor($fields, $this->getModelName($input->modelName), 'form');
        $modelFullName = $this->getModelFullName($input->modelDirectory, $input->modelName);
        $affirmMethod = $this->getAffirmMethod($input->formRequest, $fields, $requestNameSpace);
        $classToExtendFullname = $this->getFullClassToExtend($input->extends);
        $namespacesToUse = $this->getRequiredUseClasses($fields, [$modelFullName, $requestNameSpace, $classToExtendFullname]);
        $stub = $this->getStubContent('controller');

        return $this->replaceViewNames($stub, $input->viewDirectory, $input->prefix)
                    ->replaceModelName($stub, $input->modelName)
                    ->replaceNamespace($stub, $this->getControllersNamespace())
                    ->replaceControllerExtends($stub, $this->getControllerExtends($classToExtendFullname))
                    ->replaceUseCommandPlaceholder($stub, $namespacesToUse)
                    ->replaceRouteNames($stub, $input->modelName, $input->prefix)
                    ->replaceRequestName($stub, $requestName)
                    ->replaceRequestFullName($stub, $requestNameSpace) 
                    ->replaceConstructor($stub, $this->getConstructor($input->withAuth))
                    ->replaceCallAffirm($stub, $this->getCallAffirm($input->formRequest))
                    ->replaceAffirmMethod($stub, $affirmMethod)
                    ->replacePaginationNumber($stub, $input->perPage)
                    ->replaceFileSnippet($stub, $this->getFileSnippet($fields))
                    ->replaceBooleadSnippet($stub, $this->getBooleanSnippet($fields))
                    ->replaceStringToNullSnippet($stub, $this->getStringToNullSnippet($fields))
                    ->replaceFileMethod($stub, $this->getUploadFileMethod($fields))
                    ->replaceViewVariablesForIndex($stub, $viewVariablesForIndex)
                    ->replaceViewVariablesForShow($stub, $viewVariablesForShow)
                    ->replaceViewVariablesForCreate($stub, $this->getCompactVariablesFor($fields, null, 'form'))
                    ->replaceViewVariablesForEdit($stub, $viewVariablesForEdit)
                    ->replaceWithRelationsForIndex($stub, $this->getWithRelationFor($fields, 'index'))
                    ->replaceWithRelationsForShow($stub, $this->getWithRelationFor($fields, 'show'))
                    ->replaceRelationCollections($stub, $this->getRequiredRelationCollections($fields))
                    ->replaceOnStoreAction($stub, $this->getOnStoreAction($fields))
                    ->replaceOnUpdateAction($stub, $this->getOnUpdateAction($fields))
                    ->replaceAppName($stub, $this->getAppName())
                    ->replaceControllerName($stub, $input->controllerName)
                    ->replaceDataVariable($stub, 'data')
                    ->createFile($destenationFile, $stub)
                    ->info('A controller was crafted successfully.');
    }

    /**
     * Gets controller's constructor.
     *
     * @param bool $withAuth
     *
     * @return string
     */
    protected function getConstructor($withAuth)
    {
        $stub = $this->getStubContent('controller-constructor');

        $middleware = $withAuth ? '$this->middleware(\'auth\');' : '';

        $this->replaceAuthMiddlewear($stub, $middleware);

        $starts = strpos($stub, '{');
        $ends = strrpos($stub, '}');

        if($starts !== false && $ends !== false)
        {
            $content = trim(substr($stub, $starts +1, $ends-$starts-1));
            if(!empty($content)) {
                return $stub; 
            }
        }

        return '';
    }

    /**
     * Gets the code to extend the controller.
     *
     * @param string $namespace
     *
     * @return string
     */
    protected function getControllerExtends($namespace)
    {
        $class = $this->extractClassFromString($namespace);
        if (!empty($class)) {
            return sprintf('extends %s', $class);
        }

        return '';
    }

    /**
     * Gets the full class name to extend
     *
     * @param string $extend
     *
     * @return string
     */
    protected function getFullClassToExtend($extend)
    {
        if (empty($extend)) {
            return '';
        }
        $appNamespace = $this->getAppNamespace();
        
        if (starts_with($extend, $appNamespace)) {
            $extend = str_replace($appNamespace, '', $extend);
        }

        return $appNamespace . trim($extend, '\\');
    }

    /**
     * Gets the setter action for the giving field on-store.
     *
     * @param array $fields
     * @param string $view
     *
     * @return string
     */
    protected function getOnStoreAction(array $fields)
    {
        $final = [];

        foreach ($fields as $field) {
            $action = $this->extractClassFromString($field->onStore);
            if (!empty($action)) {
                $final[] = $this->getArrayReadyString($field->name, $action);
            }
        }

        return implode(PHP_EOL, $final);
    }

    /**
     * Gets the setter action for the giving field on-update.
     *
     * @param array $fields
     * @param string $view
     *
     * @return string
     */
    protected function getOnUpdateAction(array $fields)
    {
        $final = [];

        foreach ($fields as $field) {
            $action = $this->extractClassFromString($field->onUpdate);
            if (!empty($action)) {
                $final[] = $this->getArrayReadyString($field->name, $action);
            }
        }

        return implode(PHP_EOL, $final);
    }

    /**
     * Get a string to set a giving $variable with a $key and $value pair
     *
     * @param string $key
     * @param string $value
     * @param string $variable
     *
     * @return string
     */
    protected function getArrayReadyString($key, $value, $variable = '$data')
    {
        $value = trim(rtrim($value, ';'));

        return sprintf("%s['%s'] = %s;", $variable, $key, $value);
    }

    /**
     * Gets the needed compact variables for the edit/create views.
     *
     * @param array $fields
     * @param string $view
     *
     * @return string
     */
    protected function getWithRelationFor(array $fields, $view)
    {
        $variables = [];
        $collections = $this->getRelationCollections($fields, $view);

        foreach ($collections as $collection) {
            if (! in_array($collection->name, $variables)) {
                $variables[] = strtolower($collection->name);
            }
        }

        return $this->getWithRelationsStatement($variables);
    }

    /**
     * Gets the needed compact variables for the edit/create views.
     *
     * @param array $fields
     *
     * @return string
     */
    protected function getRequiredRelationCollections(array $fields)
    {
        $variables = [];

        $collections = $this->getRelationCollections($fields, 'form');

        foreach ($collections as $collection) {
            $accesor = $this->getRelationAccessor($collection);
            
            if (! in_array($accesor, $variables)) {
                $variables[] = $accesor;
            }
        }

        return implode(PHP_EOL, $variables);
    }

    /**
     * Gets the needed models to use for in the controller.
     *
     * @param array $fields
     * @param array $additions
     *
     * @return string
     */
    protected function getRequiredUseClasses(array $fields, array $additions = [])
    {
        $commands = [];

        foreach ($additions as $addition) {
            $commands[] = $this->getUseClassCommand($addition);
        }

        $collections = $this->getRelationCollections($fields, 'form');

        foreach ($collections as $collection) {
            $command = $this->getUseClassCommand($collection->getFullForeignModel());

            if (! in_array($command, $commands)) {
                $commands[] = $this->getUseClassCommand($collection->getFullForeignModel());
            }
        }

        // Attempt to include classes that don't start with \\ with using command.
        foreach ($fields as $field) {
            if (! empty($field->onStore) && ! in_array($field->onStore, $commands)) {
                $commands[] = $this->extractNamespace($field->onStore);
            }

            if (! empty($field->onUpdate) && ! in_array($field->onUpdate, $commands)) {
                $commands[] = $this->extractNamespace($field->onUpdate);
            }
        }

        usort($commands, function ($a, $b) {
            return strlen($a)-strlen($b);
        });

        return implode(PHP_EOL, $commands);
    }

    /**
     * Extracts a namespace from a giving string
     *
     * @param string $string
     *
     * @return string
     */
    protected function extractNamespace($string)
    {
        $string = trim($string);

        if ($this->isQualifiedNamespace($string) && ($index = strrpos($string, '::')) != false) {
            $namespace = substr($string, 0, $index);

            if (! empty($namespace)) {
                return $this->getUseClassCommand($namespace);
            }
        }

        return null;
    }

    /**
     * Extracts a namespace from a giving string
     *
     * @param string $string
     *
     * @return string
     */
    protected function extractClassFromString($string)
    {
        $string = trim($string);

        if ($this->isQualifiedNamespace($string)) {
            if (($index = strrpos($string, '::')) !== false) {
                $subString = substr($string, 0, $index);

                if (($positionOfSlash = strrpos($subString, '\\')) != false) {
                    return substr($string, $positionOfSlash + 1);
                }
            }

            if (($index = strrpos($string, '\\')) !== false) {
                $string = substr($string, $index + 1);
            }
        }

        return $string;
    }

    /**
     * Checks if a string is a qualified namespace.
     *
     * @param string $name
     *
     * @return bool
     */
    protected function isQualifiedNamespace($name)
    {
        return ! empty($name) && ! starts_with($name, '\\');
    }

    /**
     * Gets the relation accessor for the giving foreign renationship.
     *
     * @param string $name
     *
     * @return string
     */
    protected function getUseClassCommand($name)
    {
        return sprintf('use %s;', $name);
    }

    /**
     * Gets the relation accessor for the giving foreign renationship.
     *
     * @param CrestApps\CodeGenerator\Models\ForeignRelationship $collection
     *
     * @return string
     */
    protected function getRelationAccessor(ForeignRelationship $collection)
    {

        return sprintf('$%s = %s::pluck(\'%s\',\'%s\')->all();', 
                            $collection->getCollectionName(), 
                            $collection->getForeignModel(), 
                            $collection->getField(),
                            $collection->getPrimaryKeyForForeignModel()
                     );
    }

    /**
     * Gets the needed compact variables for the edit/create views.
     *
     * @param array $fields
     *
     * @return string
     */
    protected function getCompactVariablesFor(array $fields, $modelName, $view)
    {
        $variables = [];

        if (! empty($modelName)) {
            $variables[] = $modelName;
        }

        if ($view == 'form') {
            $collections = $this->getRelationCollections($fields, $view);

            foreach ($collections as $collection) {
                $variables[] = $collection->getCollectionName();
            }
        }

        return $this->getCompactVariables($variables);
    }

    /**
     * Gets the needed compact variables for the edit/create views.
     *
     * @param array $fields
     *
     * @return array
     */
    protected function getRelationCollections(array $fields, $view)
    {
        $variables = [];

        foreach ($fields as $field) {
            if ($field->hasForeignRelation() && $field->isOnView($view)) {
                $variables[] = $field->getForeignRelation();
            }
        }

        return $variables;
    }

    /**
     * Converts giving array of variables to a compact statements.
     *
     * @param array $variables
     *
     * @return string
     */
    protected function getCompactVariables(array $variables)
    {
        if (empty($variables)) {
            return '';
        }

        return sprintf(', compact(%s)', implode(',', Helpers::wrapItems($variables)));
    }

    /**
     * Converts giving array of relation name to a with() statements.
     *
     * @param array $variables
     *
     * @return string
     */
    protected function getWithRelationsStatement(array $relations)
    {
        if (empty($relations)) {
            return '';
        }

        return sprintf('with(%s)->', implode(',', Helpers::wrapItems($relations)));
    }

    /**
     * Gets the method's stub that handels the file uploading.
     *
     * @param array $fields
     *
     * @return string
     */
    protected function getUploadFileMethod(array $fields)
    {
        if ($this->containsfile($fields)) {
            return $this->getStubContent('controller-upload-method', $this->getTemplateName());
        }

        return '';
    }

    /**
     * Gets the controller's fullname
     *
     * @return string
     */
    protected function getDestenationFile($name)
    {
        $path = base_path($this->getAppNamespace() . Config::getControllersPath());

        return Helpers::postFixWith($path, '/') . $name . '.php';
    }

    /**
     * Gets the Requests namespace
     *
     * @return string
     */
    protected function getRequestsNamespace($name)
    {
        $path = $this->getAppNamespace() . Config::getRequestsPath();

        return Helpers::convertSlashToBackslash($path) . $name;
    }

    /**
     * Gets the controllers namespace
     *
     * @return string
     */
    protected function getControllersNamespace()
    {
        $path = $this->getAppNamespace() . Config::getControllersPath();

        return rtrim(Helpers::convertSlashToBackslash($path), '\\');
    }

    /**
     * Checks if a giving fields array conatins at least one multiple answers' field.
     *
     * @param array
     *
     * @return bool
     */
    protected function isContainMultipleAnswers(array $fields)
    {
        $filtered = array_filter($fields, function ($field) {
            return $field->isMultipleAnswers;
        });

        return count($filtered) > 0;
    }

    /**
     * Calls the create:form-request command
     *
     * @param  CrestApps\CodeGenerator\Models\ViewInput $input
     *
     * @return $this
     */
    protected function makeFormRequest($input)
    {
        $this->callSilent('create:form-request',
        [
            'class-name' => $input->formRequestName,
            '--fields' => $input->fields,
            '--force' => $input->force,
            '--fields-file' => $input->fieldsFile,
            '--template-name' => $input->template
        ]);

        return $this;
    }

    /**
     * Gets the full model name
     *
     * @param  string $directory
     * @param  string $name
     *
     * @return string
     */
    protected function getModelFullName($directory, $name)
    {
        $final = !empty($directory) ? Config::getModelsPath() . Helpers::getPathWithSlash($directory) : Config::getModelsPath();

        return Helpers::convertSlashToBackslash($this->getAppNamespace() . $final . $name);
    }

    /**
     * Gets a clean command-line arguments and options.
     *
     * @return object
     */
    protected function getCommandInput()
    {
        $controllerName = Helpers::postFixWith(trim($this->argument('controller-name')), 'Controller');
        $plainControllerName= str_singular(Helpers::removePostFixWith($controllerName, 'Controller'));
        $modelName = $this->option('model-name') ?: $plainControllerName;
        $viewDirectory = $this->option('views-directory');
        $prefix = $this->option('routes-prefix');
        $perPage = intval($this->option('models-per-page'));
        $fields = $this->option('fields');
        $fieldsFile = $this->option('fields-file');
        $langFile = $this->option('lang-file-name') ?: strtolower(str_plural($modelName));
        $formRequest = $this->option('with-form-request');
        $force = $this->option('force');
        $modelDirectory = $this->option('model-directory');
        $formRequestName = $plainControllerName . 'FormRequest';
        $template = $this->getTemplateName();
        $extends = $this->generatorOption('controller-extends');
        $withAuth = $this->option('with-auth');

        return (object) compact('viewDirectory', 'viewName', 'modelName', 'prefix', 'perPage', 'fileSnippet', 'modelDirectory',
                                'langFile', 'fields', 'formRequest', 'formRequestName', 'force', 'fieldsFile', 'template',
                                'controllerName', 'extends','withAuth');
    }

    /**
     * Replaces on_store_setter
     *
     * @param  string  $stub
     * @param  string  $commands
     *
     * @return $this
     */
    protected function replaceOnStoreAction(&$stub, $commands)
    {
        $stub = $this->strReplace('on_store_setter', $commands, $stub);

        return $this;
    }

    /**
     * Replaces the auth middleware
     *
     * @param  string  $stub
     * @param  string  $middleware
     *
     * @return $this
     */
    protected function replaceAuthMiddlewear(&$stub, $middleware)
    {
        $stub = $this->strReplace('auth_middleware', $middleware, $stub);

        return $this;
    }

    /**
     * Replaces the auth contructor
     *
     * @param  string  $stub
     * @param  string  $contructor
     *
     * @return $this
     */
    protected function replaceConstructor(&$stub, $contructor)
    {
        $stub = $this->strReplace('constructor', $contructor, $stub);

        return $this;
    }

    /**
     * Replaces controller_extends
     *
     * @param  string  $stub
     * @param  string  $commands
     *
     * @return $this
     */
    protected function replaceControllerExtends(&$stub, $commands)
    {
        $stub = $this->strReplace('controller_extends', $commands, $stub);

        return $this;
    }

    /**
     * Replaces the data_variable.
     *
     * @param  string  $stub
     * @param  string  $commands
     *
     * @return $this
     */
    protected function replaceDataVariable(&$stub, $commands)
    {
        $stub = $this->strReplace('data_variable', $commands, $stub);

        return $this;
    }

    /**
     * Replaces on_update_setter
     *
     * @param  string  $stub
     * @param  string  $commands
     *
     * @return $this
     */
    protected function replaceOnUpdateAction(&$stub, $commands)
    {
        $stub = $this->strReplace('on_update_setter', $commands, $stub);

        return $this;
    }

    /**
     * Replaces useCommandPlaceHolder
     *
     * @param  string  $stub
     * @param  string  $commands
     *
     * @return $this
     */
    protected function replaceUseCommandPlaceholder(&$stub, $commands)
    {
        $stub = $this->strReplace('use_command_placeholder', $commands, $stub);

        return $this;
    }

    /**
     * Replaces the form-request's name for the given stub.
     *
     * @param  string  $stub
     * @param  string  $name
     *
     * @return $this
     */
    protected function replaceRequestName(&$stub, $name)
    {
        $stub = $this->strReplace('request_name', $name, $stub);

        return $this;
    }
    
    /**
     * Replaces call affirm for the given stub.
     *
     * @param  string  $stub
     * @param  string  $name
     *
     * @return $this
     */
    protected function replaceCallAffirm(&$stub, $name)
    {
        $stub = $this->strReplace('call_affirm', $name, $stub);

        return $this;
    }

    /**
     * Replaces affirm method for the given stub.
     *
     * @param  string  $stub
     * @param  string  $name
     *
     * @return $this
     */
    protected function replaceAffirmMethod(&$stub, $name)
    {
        $stub = $this->strReplace('affirm_method', $name, $stub);

        return $this;
    }

    /**
     * Replace sthe form-request's fullname for the given stub.
     *
     * @param  string  $stub
     * @param  string  $name
     *
     * @return $this
     */
    protected function replaceRequestFullName(&$stub, $name)
    {
        $stub = $this->strReplace('request_fullname', $name, $stub);

        return $this;
    }

    /**
     * Replaces the models per page total for the given stub.
     *
     * @param $stub
     * @param $total
     *
     * @return $this
     */
    protected function replacePaginationNumber(&$stub, $total)
    {
        $stub = $this->strReplace('models_per_page', $total, $stub);

        return $this;
    }

    /**
     * Replaces the file snippet for the given stub.
     *
     * @param $stub
     * @param $snippet
     *
     * @return $this
     */
    protected function replaceFileSnippet(&$stub, $snippet)
    {
        $stub = $this->strReplace('file_snippet', $snippet, $stub);

        return $this;
    }

    /**
     * Replaces the boolean snippet for the given stub.
     *
     * @param  string  $stub
     * @param  string  $snippet
     *
     * @return $this
     */
    protected function replaceBooleadSnippet(&$stub, $snippet)
    {
        $stub = $this->strReplace('boolean_snippet', $snippet, $stub);

        return $this;
    }

    /**
     * Replaces the form-request's name for the given stub.
     *
     * @param  string  $stub
     * @param  string  $snippet
     *
     * @return $this
     */
    protected function replaceStringToNullSnippet(&$stub, $snippet)
    {
        $stub = $this->strReplace('string_to_null_snippet', $snippet, $stub);

        return $this;
    }

    /**
     * Replaces the upload-method's code for the given stub.
     *
     * @param $stub
     * @param $method
     *
     * @return $this
     */
    protected function replaceFileMethod(&$stub, $method)
    {
        $stub = $this->strReplace('upload_method', $method, $stub);

        return $this;
    }

    /**
     * Replaces the create-index variables.
     *
     * @param $stub
     * @param $variables
     *
     * @return $this
     */
    protected function replaceViewVariablesForIndex(&$stub, $variables)
    {
        $stub = $this->strReplace('view_variables_for_index', $variables, $stub);

        return $this;
    }

    /**
     * Replaces the create-view variables.
     *
     * @param $stub
     * @param $variables
     *
     * @return $this
     */
    protected function replaceViewVariablesForCreate(&$stub, $variables)
    {
        $stub = $this->strReplace('view_variables_for_create', $variables, $stub);

        return $this;
    }

    /**
     * Replaces the create-edit variables.
     *
     * @param $stub
     * @param $variables
     *
     * @return $this
     */
    protected function replaceViewVariablesForEdit(&$stub, $variables)
    {
        $stub = $this->strReplace('view_variables_for_edit', $variables, $stub);

        return $this;
    }

    /**
     * Replaces the create-show variables.
     *
     * @param $stub
     * @param $variables
     *
     * @return $this
     */
    protected function replaceViewVariablesForShow(&$stub, $variables)
    {
        $stub = $this->strReplace('view_variables_for_show', $variables, $stub);

        return $this;
    }

    /**
     * Replaces withRelationsForIndex for the giving stub.
     *
     * @param $stub
     * @param $relations
     *
     * @return $this
     */
    protected function replaceWithRelationsForIndex(&$stub, $relations)
    {
        $stub = $this->strReplace('with_relations_for_index', $relations, $stub);

        return $this;
    }

    /**
     * Replaces withRelationsForShow for the giving stub.
     *
     * @param $stub
     * @param $relations
     *
     * @return $this
     */
    protected function replaceWithRelationsForShow(&$stub, $relations)
    {
        $stub = $this->strReplace('with_relations_for_show', $relations, $stub);

        return $this;
    }

    /**
     * Replaces relationCollections for the giving stub.
     *
     * @param $stub
     * @param $collections
     *
     * @return $this
     */
    protected function replaceRelationCollections(&$stub, $collections)
    {
        $stub = $this->strReplace('relation_collections', $collections, $stub);

        return $this;
    }

    /**
     * Gets the desired class name from the command-line.
     *
     * @return string
     */
    public function getNameInput()
    {
        $name = Helpers::upperCaseEveyWord(trim($this->argument('controller-name')));
        $path = Config::getControllersPath();
        $directory = trim($this->option('controller-directory'));

        if (!empty($directory)) {
            $path .= Helpers::getPathWithSlash($directory);
        }

        return Helpers::convertSlashToBackslash($path . Helpers::postFixWith($name, 'Controller'));
    }
    
    /**
     * Gets the code that call the file-upload's method.
     *
     * @param array $fields
     *
     * @return string
     */
    protected function getFileSnippet(array $fields)
    {
        $code = '';
        $template = <<<EOF
        if (\$request->hasFile('%s')) {
            \$data['%s'] = \$this->moveFile(\$request->file('%s'));
        }
EOF;

        foreach ($fields as $field) {
            if ($field->isFile()) {
                $code .= sprintf($template, $field->name, $field->name, $field->name);
            }
        }

        return $code;
    }

    /**
     * Gets the code that is needed to check for bool property.
     *
     * @param array $fields
     *
     * @return string
     */
    protected function getBooleanSnippet(array $fields)
    {
        $code = '';

        foreach ($fields as $field) {
            if ($field->isBoolean() && $field->isCheckbox()) {
                $code .= sprintf("        \$data['%s'] = \$request->has('%s');", $field->name, $field->name) . PHP_EOL;
            }
        }

        return $code;
    }

    /**
     * Gets the affirm method.
     *
     * @param bool $isFormRequest
     * @param array $fields
     *
     * @return string
     */
    protected function getAffirmMethod($withFormRequest, array $fields, $requestNamespace)
    {
        if ($withFormRequest) {
            return '';
        }
        
        $stub = $this->getStubContent('controller-affirm-method');

        $this->replaceValidationRules($stub, $this->getValidationRules($fields))
             ->replaceRequestFullName($stub, $requestNamespace);

        return $stub;
    }

    /**
     * Gets the affirm method call.
     *
     * @param bool $isFormRequest
     *
     * @return string
     */
    protected function getCallAffirm($withFormRequest)
    {
        if ($withFormRequest) {
            return '';
        }

        return '$this->affirm($request);';
    }

    /**
     * Gets the code that is needed to convert empty string to null.
     *
     * @param array $fields
     *
     * @return string
     */
    protected function getStringToNullSnippet(array $fields)
    {
        $code = '';

        foreach ($fields as $field) {
            if ($field->isNullable && !$field->isPrimary && !$field->isAutoIncrement && !$field->isRequired() && !$field->isBoolean() && !$field->isFile()) {
                $code .= sprintf("        \$data['%s'] = !empty(\$request->input('%s')) ? \$request->input('%s') : null;", $field->name, $field->name, $field->name) . PHP_EOL;
            }
        }

        return $code;
    }
}
