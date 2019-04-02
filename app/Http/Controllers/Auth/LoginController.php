<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Support\Facades\DB;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    /**
     * Return configurations for institution.
     *
     * TODO
     * Move this logic to middleware.
     *
     * @return object
     */
    private function getConfig()
    {
        return DB::table('pmieducar.configuracoes_gerais as cg')
            ->select('cg.*')
            ->join('pmieducar.instituicao as i', 'cod_instituicao', '=', 'ref_cod_instituicao')
            ->where('i.ativo', 1)
            ->first();
    }

    /**
     * @inheritdoc
     */
    public function showLoginForm()
    {
        return view('auth.login', [
            'config' => $this->getConfig(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function username()
    {
        return 'login';
    }
}
