<?php

namespace App\Admin\Controllers;

use App\Models\Customer;
use App\Models\Admin_user;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use App\Models\Event;
use App\Admin\Traits\Customfields;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Admin;
use App\Admin\Traits\ShareCustomers;
use App\Admin\Traits\Selector;
use App\Admin\RowAction\ChangeState;
use Dcat\Admin\Widgets\Tab;
use Illuminate\Http\Request;

class LeadController extends AdminController
{
    use Customfields,Selector,ShareCustomers;

    public function __construct(Request $request)
    {
        $this->source_id = $request->source_id;
        return $this;
    }

    public static $editcss = [
        '/static/css/lead_edit.css',
    ];
    public static $showcss = [
        '/static/css/customer_show.css',
    ];
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(Customer::with(['admin_users']), function (Grid $grid) {


            Admin::style(
                <<<CSS
        .nav-tabs {
            background-color: #fff;
            margin-top: 20px;
            box-shadow: 0 2px 4px 0 rgba(0,0,0,.05);
            border-radius: .25rem;
        }
CSS
            );

            if ((!$this->source_id || $this->source_id == 0) && Admin::user()->isRole('administrator')) {
                $grid->model();
            } elseif ($this->source_id == 2) {
                $shares_Customer = array_column(Admin_user::find(Admin::user()->id)->shares_Customer()->get()->toArray(), 'id');
                $grid->model()->whereIn('id', $shares_Customer);
            } else {
                $grid->model()->where('admin_users_id', '=', Admin::user()->id);
            }

            $grid->header(function () {
                $tab = Tab::make();
                if (Admin::user()->isRole('administrator')) {
                    $tab->addLink('所有线索', '?source_id=0',true);
                }
                $tab->addLink('我的线索', '?source_id=1',$this->source_id==1 ? true : false);
                $tab->addLink('分享给我', '?source_id=2',$this->source_id==2 ? true : false);
                // $tab->addLink('公海客户', '?source_id=3',$this->source_id==3 ? true : false);
                return $tab;
            });

            $grid->selector(function (Grid\Tools\Selector $selector) {
                $selector->selectOne('state', '状态', [
                    0 => '废弃',
                    1 => '正常',
                ]);
                $selector->select('id', '未跟进', ['3天未跟进', '1周未跟进', '半月未跟进', '1月未跟进', '2月未跟进', '半年未跟进'], function ($query, $value) {
                    $between = [
                        $this->queryCustomer(3),
                        $this->queryCustomer(7),
                        $this->queryCustomer(15),
                        $this->queryCustomer(30),
                        $this->queryCustomer(60),
                        $this->queryCustomer(180),
                    ];
                    $value = current($value);
                    $query->whereIn('id', $between[$value]);
                });
            });
            $grid->setDialogFormDimensions('700px', '420px');
            $grid->id->sortable();
            $grid->column('state', '状态')->using([
                0 => '废弃',
                1 => '正常',
            ])->label([
                '0' => 'gray',
                '1' => 'success',
            ]);
            $grid->column('events', '跟进')->display(function () {
                $Event = Event::where([['customer_id', '=', $this->id]])->orderBy('updated_at', 'desc')->limit(1)->get();
                if (count($Event)) {
                    return $Event[0]['created_at']->diffForHumans();
                } else {
                    return '<span style="color:#ea5455">无跟进</span>';
                }
            });
            $grid->name('客户名称')->link(function () {
                return admin_url('leads/' . $this->id);
            });
            $grid->column('admin_users.name', '所属销售');
            $grid->created_at;

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if ($actions->row->state == 1) {
                    $actions->append(new ChangeState(['Customer','转为客户', '您确定要将此线索转化为正式客户吗', 3]));
                    $actions->append(new ChangeState(['Customer','废弃', '确定废弃此线索吗？', 0]));
                } else {
                    $actions->append(new ChangeState(['Customer','恢复', '您确定要恢复此线索吗？', 1]));
                }
            });

            $grid->setActionClass(Grid\Displayers\Actions::class);
            $grid->disableDeleteButton();
            $grid->enableDialogCreate();
            $grid->disableBatchActions();
            $grid->disableViewButton();
            $grid->disableEditButton();
            $grid->model()->orderBy('id', 'desc');
            $grid->model()->where('state', '!=', '3');
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->like('name', '客户名称');
            });
        });
    }


    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    public function show($id, Content $content)
    {
        // 判断授权，无权限查看他人的信息,以后可以优化一下
        $detalling = Admin::user()->id != Customer::find($id)->admin_users->id;
        $Role = !Admin::user()->isRole('administrator');
        if ($Role && $detalling) {
            $customer = Customer::find($id);
            $this->authorize('update', $customer);
        }


        Admin::css(static::$showcss);
        $customer = Customer::with('contacts','contracts','admin_users','events','attachments','shares_user')->findorFail($id);
        // $fields = Customfield::where([['model', '=', 'customer'], ['show', '=', '1'],])->get();
        $data = [
            'customer' => $customer,
            'contacts' => $customer->contacts,
            'admin_users' => $customer->admin_users,
            'events' => $customer->events()->orderBy('updated_at', 'desc')->get(),
            'contracts' => $customer->contracts,
            'attachments' => $customer->attachments()->orderBy('updated_at', 'desc')->get(),
            'customerfields' => $this->custommodel('customer'),
            'contactfields' => $this->custommodel('contact'),
            // 'fields' => $fields,
            'Share' => $this->Share($id),
            'shares_user' => $customer->shares_user()->select(['name','avatar'])->get(),
        ];
        return $content
            ->title('线索')
            ->description('详情')
            ->body($this->_detail($data));
    }
    private function _detail($data)
    {
        return view('admin/customer/show', $data);
    }


    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        Admin::css(static::$editcss);
        $builder = Customer::with('contacts');
        return Form::make($builder, function (Form $form) {
            // 判断授权，无权限编辑他人的信息,以后可以优化一下
            // dd($form->model()->admin_users_id);
            $Editing = $form->isEditing() && Admin::user()->id != $form->model()->admin_users_id;
            if ($Editing) {
                $customer = Customer::find($form->model()->id);
                $this->authorize('update', $customer);
            }
            $form->display('id');
            $form->text('name');
            $form->hidden('admin_users_id')->value(Admin::user()->id);
            $form->hidden('state')->value(0);

            $form->fieldset('联系人', function (Form $form) {
                $form->hasMany('contacts', '联系人', function (Form\NestedForm $form) {
                    $form->text('name', '姓名');
                    $form->mobile('phone', '手机号');
                    // $form->hidden('customer_id')->value('id');
                });
            });
        });
    }
}
