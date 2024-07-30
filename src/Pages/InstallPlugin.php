<?php

namespace Startupful\StartupfulPlugin\Pages;

use Filament\Pages\Page;
use Filament\Forms;
use Startupful\StartupfulPlugin\Models\Plugin;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Startupful\StartupfulPlugin\StartupfulPlugin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Composer\Autoload\ClassLoader;

class InstallPlugin extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';
    protected static string $view = 'startupful::pages.install-plugin';
    protected static ?string $navigationGroup = 'Startupful Plugin';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'startupful-install-plugin';

    public static function getNavigationLabel(): string
    {
        return 'Install New Plugin';
    }

    public ?string $search = '';
    public Collection $plugins;
    public ?string $installationStatus = null;

    public function mount(): void
    {
        $this->plugins = collect(StartupfulPlugin::getGithubRepo()->getStartupfulPlugins());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('search')
                    ->placeholder('Search for Startupful plugins...')
                    ->reactive()
                    ->afterStateUpdated(fn () => $this->searchPlugins()),
            ]);
    }

    public function searchPlugins(): void
    {
        $results = StartupfulPlugin::getGithubRepo()->searchPlugins($this->search);
        $this->plugins = collect($results);
    }

    public function installPlugin($plugin): void
    {
        $plugin = is_string($plugin) ? json_decode($plugin, true) : $plugin;

        if (!is_array($plugin)) {
            $this->installationStatus = "Error: Invalid plugin data";
            Notification::make()
                ->title("Failed to install plugin: Invalid data format")
                ->danger()
                ->send();
            return;
        }

        $this->installationStatus = "Installing {$plugin['name']}...";

        try {
            $version = $plugin['latest_version'] ?? 'dev-master';
            
            // 패키지 업데이트 명령으로 변경
            $command = ['composer', 'require', '--no-cache', "{$plugin['package_name']}"];
            
            $env = getenv();
            $env['HOME'] = base_path();
            $env['COMPOSER_HOME'] = sys_get_temp_dir() . '/.composer';

            $process = new Process($command, base_path(), $env);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            // 플러그인 설치 명령 실행
            $this->runDefaultInstallationSteps($plugin);

            $this->addToInstalledPlugins($plugin);

            $this->installationStatus = "Plugin {$plugin['name']} installed successfully.";
            Notification::make()
                ->title("Plugin '{$plugin['name']}' installed successfully.")
                ->success()
                ->send();
        } catch (\Exception $e) {
            $this->installationStatus = "Failed to install {$plugin['name']}. Error: " . $e->getMessage();
            Notification::make()
                ->title("Failed to install plugin")
                ->body("Error: " . $e->getMessage() . "\nPlease check the logs for more details.")
                ->danger()
                ->send();
        }
    }

    protected function addToInstalledPlugins($plugin): void
    {
        Plugin::create([
            'name' => $plugin['name'],
            'version' => $plugin['latest_version'] ?? 'unknown',
            'description' => $plugin['description'] ?? '',
            'developer' => $plugin['full_name'] ?? '',
            'is_active' => true,
            'installed_at' => now(),
        ]);
    }

    protected function runDefaultInstallationSteps($plugin): void
    {
        $name = $plugin['name'] ?? '';
        $className = str_replace('-', '', ucwords($name, '-'));
        
        \Log::info("Starting installation steps for {$name}");

        try {
            $providerClass = "Startupful\\{$className}\\{$className}ServiceProvider";
            \Log::info("Looking for service provider: {$providerClass}");

            if (!class_exists($providerClass)) {
                \Log::error("Service provider class {$providerClass} not found");
                throw new \Exception("Service provider class {$providerClass} not found");
            }

            \Log::info("Service provider found: {$providerClass}");

            // 마이그레이션 파일 발행
            \Log::info("Attempting to publish migrations for {$name}");
            $publishOutput = Artisan::call('vendor:publish', [
                '--provider' => $providerClass,
                '--tag' => 'migrations'
            ]);
            \Log::info("Publish command output: " . Artisan::output());

            // 발행된 마이그레이션 파일 찾기
            $migrationFiles = glob(database_path('migrations/*_create_avatar_chat_tables.php'));
            \Log::info("Found migration files:", $migrationFiles);

            if (empty($migrationFiles)) {
                \Log::error("No migration files found for {$name} after publishing");
                throw new \Exception("No migration files found for {$name} after publishing");
            }

            // 마이그레이션 실행
            \Log::info("Running migrations for {$name}");
            $output = Artisan::call('migrate', ['--force' => true]);
            \Log::info("Migration command output: " . Artisan::output());

            if ($output !== 0) {
                \Log::error("Migration failed for {$name}. Output: " . Artisan::output());
                throw new \Exception("Migration failed for {$name}. Output: " . Artisan::output());
            }

            \Log::info("Migrations completed for {$name}");

            // AdminPanelProvider.php 파일 수정
            $providerPath = app_path('Providers/Filament/AdminPanelProvider.php');
            \Log::info("Updating AdminPanelProvider.php at: {$providerPath}");

            if (file_exists($providerPath)) {
                $content = file_get_contents($providerPath);
                
                // use 문 추가
                $useStatement = "use Startupful\\{$className}\\{$className}Plugin;";
                \Log::info("Adding use statement: {$useStatement}");

                if (!str_contains($content, $useStatement)) {
                    $content = str_replace("namespace App\Providers\Filament;", "namespace App\Providers\Filament;\n\n{$useStatement}", $content);
                    \Log::info("Use statement added");
                } else {
                    \Log::info("Use statement already exists");
                }
                
                // plugin 메서드 추가
                $pluginMethod = "->plugin({$className}Plugin::make())";
                \Log::info("Adding plugin method: {$pluginMethod}");

                if (!str_contains($content, $pluginMethod)) {
                    $content = preg_replace(
                        '/(\->login\(\))/',
                        "$1\n            {$pluginMethod}",
                        $content
                    );
                    \Log::info("Plugin method added");
                } else {
                    \Log::info("Plugin method already exists");
                }
                
                file_put_contents($providerPath, $content);
                \Log::info("AdminPanelProvider.php updated successfully");
            } else {
                \Log::error("AdminPanelProvider.php not found at: {$providerPath}");
            }
            
            $this->installationStatus = "Completed installation steps for {$name}";
            \Log::info("Installation completed for {$name}");
        } catch (\Exception $e) {
            $this->installationStatus = "Installation failed for {$name}: " . $e->getMessage();
            \Log::error("Installation failed for {$name}: " . $e->getMessage(), [
                'exception' => $e,
                'plugin' => $plugin
            ]);
            throw $e;
        }
    }
}