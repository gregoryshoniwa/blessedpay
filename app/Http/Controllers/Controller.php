<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The signed-in user, who must hold this ability (403 otherwise). */
    protected function need(Request $request, string $ability): User
    {
        $user = $request->user();
        abort_unless($user && $user->may($ability), 403, 'Your role does not allow this.');

        return $user;
    }

    /**
     * A company this user may touch. Someone else's company is a 404, not a
     * 403, so ids can't be used to learn which companies exist.
     */
    protected function company(Request $request, int|string $id): Company
    {
        $company = Company::find($id);
        abort_unless($company && $request->user()->reaches($company->id), 404);

        return $company;
    }

    /**
     * Which company's rows a list should show: a member always sees their own;
     * staff see everything unless they filter with ?company_id=.
     */
    protected function companyFilter(Request $request): ?int
    {
        $user = $request->user();

        if (! $user->isStaff()) {
            return $user->company_id;
        }

        return $request->filled('company_id') ? (int) $request->query('company_id') : null;
    }
}
