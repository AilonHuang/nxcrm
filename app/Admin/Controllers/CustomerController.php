<?php

namespace App\Admin\Controllers;

use App\Models\Customer;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use App\Admin\Traits\Customfields;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Admin;
use App\Models\Event;
use App\Admin\Traits\Selector;
use App\Admin\Traits\ShareCustomers;

class CustomerController extends AdminController
{
    use Customfields,Selector,ShareCustomers;

    public static $css = [
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
            if (!Admin::user()->isRole('administrator')) {
                $grid->model()->where('admin_users_id', '=', Admin::user()->id);
            }
            $grid->selector(function (Grid\Tools\Selector $selector) {
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
            $grid->id->sortable();
            $grid->name('客户名称')->link(function () {
                return admin_url('customers/' . $this->id);
            });
            // $grid->admin_users_id;
            $grid->column('events', '跟进')->display(function () {
                // 取出当前客户在跟进表内的所有跟进的最新一条
                $Event = Event::where([['customer_id', '=', $this->id]])->orderBy('updated_at', 'desc')->limit(1)->get();
                if (count($Event)) {
                    return $Event[0]['created_at']->diffForHumans();
                } else {
                    return '<span style="color:#ea5455">无跟进</span>';
                }
            });
            $this->gridfield($grid,'customer');
            $grid->column('admin_users.name', '所属销售');
            $grid->model()->where('state', '=', '3');
            $grid->disableBatchActions();
            $grid->model()->orderBy('id', 'desc');
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->like('name', '客户名称');
            });
            $top_titles = ['id' => 'ID', 'name' => '名称', 'admin_users_id' => '所属销售', 'address' => '地址'];
            $grid->export($top_titles)->rows(function (array $rows) {
                foreach ($rows as $index => &$row) {
                    $row['admin_users_id'] = Customer::find($row['admin_users_id'])->admin_users->name;
                }
                return $rows;
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


        Admin::css(static::$css);
        $customer = Customer::query()->findorFail($id);
        $contacts = Customer::find($id)->contacts;
        $contracts = Customer::find($id)->contracts;
        $admin_users = Customer::find($id)->admin_users;
        $events = Customer::find($id)->events()->orderBy('updated_at', 'desc')->get();
        $attachments = Customer::find($id)->attachments()->orderBy('updated_at', 'desc')->get();
        $data = [
            'customer' => $customer,
            'contacts' => $contacts,
            'admin_users' => $admin_users,
            'events' => $events,
            'contracts' => $contracts,
            'attachments' => $attachments,
            'customerfields' => $this->custommodel('customer'),
            'contactfields' => $this->custommodel('contact'),
            'Share' => $this->Share($id),
        ];
        return $content
            ->title('客户')
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
        return Form::make(new Customer(), function (Form $form) {
            // 判断授权，无权限编辑他人的信息,以后可以优化一下
            // dd($form->model()->admin_users_id);
            // $Editing = $form->isEditing() && Admin::user()->id != $form->model()->admin_users_id;
            // if (!$form->isCreating()) {
            //         $this->authorize('update',  [Admin::user(), $form->model()->id]);
            // }
            $form->display('id');
            $form->text('name');
            $this->formfield($form,'customer');
            $form->hidden('admin_users_id')->value(Admin::user()->id);
            $form->hidden('state')->value(3);
            $form->hidden('fields')->value(null);
            $class = $this;
            $form->saving(function (Form $form) use ($class) {
                $form_field = array();
                foreach ($class->custommodel('Customer') as $field) {
                    // dd($field);
                    $field_field = $field['field'];
                    $form_field[$field_field] = $form->$field_field;
                    $form->deleteInput($field['field']);
                }
                $form->fields = json_encode($form_field);
            });
        });
    }

}
