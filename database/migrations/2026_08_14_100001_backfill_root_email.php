<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * config('auth.root.email') мог поменяться в .env уже после того, как
     * root был засеян create_root_user-миграцией (та вставляет строку лишь
     * один раз). Приводим DB в соответствие с текущим значением — это
     * безусловно безопасно: ничего в приложении не полагается на конкретное
     * значение email root'а.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('name', config('auth.root.username'))
            ->update(['email' => config('auth.root.email')]);
    }

    public function down(): void
    {
        // Необратимо намеренно: прежнее значение нигде не сохранено, а
        // откатывать email root'а на плейсхолдер не имеет смысла.
    }
};
