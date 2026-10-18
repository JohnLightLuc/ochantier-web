<?php

namespace Tests\Feature;

use App\Enums\DocumentAudience;
use App\Models\DocumentType;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

class ModelSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function models(): array
    {
        return collect(AppServiceProvider::morphMap())
            ->mapWithKeys(fn (string $class, string $alias) => [$alias => [$class]])
            ->all();
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('models')]
    public function test_fillable_and_cast_attributes_exist_in_the_table(string $class): void
    {
        $model = new $class;
        $columns = Schema::getColumnListing($model->getTable());

        $this->assertNotEmpty($columns, "La table {$model->getTable()} n’existe pas.");
        $this->assertEmpty(array_diff($model->getFillable(), $columns), 'Colonnes fillable absentes de la table.');
        $this->assertEmpty(array_diff(array_keys($model->getCasts()), $columns), 'Colonnes castées absentes de la table.');
    }

    /**
     * @param  class-string<Model>  $class
     */
    #[DataProvider('models')]
    public function test_relations_query_existing_columns(string $class): void
    {
        $model = new $class;
        $relations = $this->relationNames($class);

        foreach ($relations as $name) {
            $relation = $model->{$name}();

            if ($relation instanceof MorphTo) {
                $columns = Schema::getColumnListing($model->getTable());
                $this->assertContains($relation->getMorphType(), $columns, "{$class}::{$name}()");
                $this->assertContains($relation->getForeignKeyName(), $columns, "{$class}::{$name}()");

                continue;
            }

            $this->assertFalse($class::query()->has($name)->exists(), "{$class}::{$name}()");
        }

        $this->addToAssertionCount(1);
    }

    public function test_postgres_arrays_are_read_and_written_as_php_arrays(): void
    {
        $withDefault = DocumentType::create(['code' => 'cni', 'label' => 'Carte nationale d’identité', 'audience' => DocumentAudience::Worker]);
        $custom = DocumentType::create([
            'code' => 'plan',
            'label' => 'Plan',
            'audience' => 'site',
            'allowed_mime' => ['application/pdf', 'image/svg+xml', 'avec, virgule', 'avec "guillemets"'],
        ]);

        $this->assertSame(['application/pdf', 'image/jpeg', 'image/png'], $withDefault->refresh()->allowed_mime);
        $this->assertSame(['application/pdf', 'image/svg+xml', 'avec, virgule', 'avec "guillemets"'], $custom->refresh()->allowed_mime);
        $this->assertSame(DocumentAudience::Site, $custom->audience);
    }

    public function test_models_use_the_aliases_of_the_morph_map(): void
    {
        foreach (AppServiceProvider::morphMap() as $alias => $class) {
            $this->assertSame($alias, (new $class)->getMorphClass());
        }
    }

    /**
     * Méthodes publiques du modèle qui déclarent renvoyer une relation Eloquent.
     *
     * @param  class-string<Model>  $class
     * @return list<string>
     */
    private function relationNames(string $class): array
    {
        $names = [];

        foreach ((new \ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();

            if ($method->class === $class && $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Relation::class)) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }
}
