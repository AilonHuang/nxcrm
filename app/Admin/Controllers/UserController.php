<?php

namespace App\Admin\Controllers;

use Dcat\Admin\Http\Controllers\UserController as User;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Admin;
use Dcat\Admin\Http\Repositories\Administrator;
use App\Models\Admin_user as AdministratorModel;
use Dcat\Admin\Show;
use Dcat\Admin\Widgets\Tree;


class UserController extends User
{
    public function title()
    {
        return trans('admin.administrator');
    }

    protected function grid()
    {
        if (Admin::user()->isRole('administrator')) {
            $model = Administrator::with(['roles']);
        } else {
            $model = AdministratorModel::with(['roles'])->where('agency_id', '=', Admin::user()->agency_id);
        }

        return Grid::make($model, function (Grid $grid) {
            $grid->column('id', 'ID')->sortable();
            $grid->column('username');
            $grid->column('name');

            if (config('admin.permission.enable')) {
                $grid->column('roles')->pluck('name')->label('primary', 3);

                // $permissionModel = config('admin.database.permissions_model');
                // $roleModel = config('admin.database.roles_model');
                // $nodes = (new $permissionModel())->allNodes();
                // $grid->column('permissions')
                //     ->if(function () {
                //         return ! $this->roles->isEmpty();
                //     })
                //     ->showTreeInDialog(function (Grid\Displayers\DialogTree $tree) use (&$nodes, $roleModel) {
                //         $tree->nodes($nodes);

                //         foreach (array_column($this->roles->toArray(), 'slug') as $slug) {
                //             if ($roleModel::isAdministrator($slug)) {
                //                 $tree->checkAll();
                //             }
                //         }
                //     })
                //     ->else()
                //     ->display('');
            }

            $grid->column('created_at')->sortable();
            // $grid->column('updated_at');

            $grid->quickSearch(['id', 'name', 'username']);

            $grid->showQuickEditButton();
            // $grid->enableDialogCreate();
            $grid->showColumnSelector();
            $grid->disableEditButton();
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if ($actions->getKey() == AdministratorModel::DEFAULT_ID || $actions->row->id == Admin::user()->id) {
                    $actions->disableDelete();
                }
            });
        });
    }

    protected function detail($id)
    {
        return Show::make($id, Administrator::with(['roles']), function (Show $show) {
            $show->field('id');
            $show->field('username');
            $show->field('name');

            $show->field('avatar', __('admin.avatar'))->image();

            if (config('admin.permission.enable')) {
                $show->field('roles')->as(function ($roles) {
                    if (!$roles) {
                        return;
                    }

                    return collect($roles)->pluck('name');
                })->label();

                $show->field('permissions')->unescape()->as(function () {
                    $roles = $this->roles->toArray();

                    $permissionModel = config('admin.database.permissions_model');
                    $roleModel = config('admin.database.roles_model');
                    $permissionModel = new $permissionModel();
                    $nodes = $permissionModel->allNodes();

                    $tree = Tree::make($nodes);

                    $isAdministrator = false;
                    foreach (array_column($roles, 'slug') as $slug) {
                        if ($roleModel::isAdministrator($slug)) {
                            $tree->checkAll();
                            $isAdministrator = true;
                        }
                    }

                    if (!$isAdministrator) {
                        $keyName = $permissionModel->getKeyName();
                        $tree->check(
                            $roleModel::getPermissionId(array_column($roles, $keyName))->flatten()
                        );
                    }

                    return $tree->render();
                });
            }

            $show->field('created_at');
            $show->field('updated_at');
        });
    }

    public function form()
    {
        return Form::make(Administrator::with(['roles']), function (Form $form) {
            $userTable = config('admin.database.users_table');

            $connection = config('admin.database.connection');

            $id = $form->getKey();

            $form->display('id', 'ID');

            $form->text('username', trans('admin.username'))
                ->required()
                ->creationRules(['required', "unique:{$connection}.{$userTable}"])
                ->updateRules(['required', "unique:{$connection}.{$userTable},username,$id"]);
            $form->text('name', trans('admin.name'))->required();
            if (config('admin.permission.enable')) {
                if (Admin::user()->isRole('administrator')) {
                    $form->multipleSelect('roles', trans('admin.roles'))
                        ->options(function () {
                            $roleModel = config('admin.database.roles_model');
                            return $roleModel::where('pid', '=', 0)->pluck('name', 'id');
                        })
                        ->customFormat(function ($v) {
                            return array_column($v, 'id');
                        });
                } else {
                    $form->multipleSelect('roles', trans('admin.roles'))
                        ->options(function () {
                            $roleModel = config('admin.database.roles_model');
                            return $roleModel::where('pid', '=', AdministratorModel::find(Admin::user()->id)->agency->role_id)->pluck('name', 'id');
                        })
                        ->customFormat(function ($v) {
                            return array_column($v, 'id');
                        });
                }
            }


            $form->image('avatar', trans('admin.avatar'))->autoUpload();
            $form->mobile('mobile', trans('admin.mobile'))->required();
            $form->text('qq', trans('admin.qq'));
            $form->text('wechat', trans('admin.wechat'));
            $form->date('birthday', trans('admin.birthday'));
            if ($id) {
                $form->password('password', trans('admin.password'))
                    ->minLength(5)
                    ->maxLength(20)
                    ->customFormat(function () {
                        return '';
                    });
            } else {
                $form->password('password', trans('admin.password'))
                    ->required()
                    ->minLength(5)
                    ->maxLength(20);
            }

            $form->password('password_confirmation', trans('admin.password_confirmation'))->same('password');

            $form->ignore(['password_confirmation']);

            $form->display('created_at', trans('admin.created_at'));
            $form->display('updated_at', trans('admin.updated_at'));

            if ($id == AdministratorModel::DEFAULT_ID) {
                $form->disableDeleteButton();
            }
        })->saving(function (Form $form) {
            if (!$form->agency_id) {
                $form->agency_id = 0;
            }
            if ($form->password && $form->model()->get('password') != $form->password) {
                $form->password = bcrypt($form->password);
            }

            if (!$form->password) {
                $form->deleteInput('password');
            }
        });
    }
}