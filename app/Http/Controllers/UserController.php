<?php

namespace App\Http\Controllers;

use App\Http\Resources\Post as PostResource;
use App\Models\Notification;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Sửa thông tin người dùng
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function postEditProfile(Request $request)
    {
        if ($request->phone == '') {
            $request->validate([
                'name' => 'required|max:250',
                'detail_address' => 'max:250',
                'national_id_number' => 'max:15',
                'id_ward' => 'numeric|min:1|max:32248',
            ], [
                'name.max' => 'Tên phải ngắn hơn 250 ký tự',
                'detail_address.max' => 'Địa chỉ cụ thể chỉ nhập số nhà/ tên đường/ thôn xóm/... và không đuợc quá 250 ký tự',
                'national_id_number.max' => 'Số CMND nhập sai định dạng',
            ]);
        } else {
            $request->validate([
                'name' => 'required|max:250',
                'detail_address' => 'max:250',
                'national_id_number' => 'max:15',
                'phone' => ['regex:/^(([\+]([\d]{2,}))([0-9\.\-\/\s]{5,})|([0-9\.\-\/\s]{5,}))*$/'],
                'id_ward' => 'numeric|min:1|max:32248',
            ], [
                'name.max' => 'Tên phải ngắn hơn 250 ký tự',
                'detail_address.max' => 'Địa chỉ cụ thể chỉ nhập số nhà/ tên đường/ thôn xóm/... và không đuợc quá 250 ký tự',
                'national_id_number.max' => 'Số CMND nhập sai định dạng',
                'phone.regex' => 'Số điện thoại sai định dạng',
            ]);
        }

        $user = $request->user();
        $user->name = $request->name;
        $user->detail_address = $request->detail_address;
        $user->national_id_number = $request->national_id_number;
        $user->phone = $request->phone;
        $user->id_ward = $request->id_ward;
        $user->save();
        return response()->json("Sửa thông tin thành công!", 200);
    }

    function postChangePassword(Request $request)
    {
        $user = $request->user();
        if (!(Hash::check($request->get('old_password'), $user->password))) {
            // The password doesn't matche
            return response()->json("Mật khẩu hiện tại của bạn nhập không đúng", 400);
        }
        $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|min:6|max:32|different:old_password',
            'password_confirmation' => 'required|same:new_password',
        ], [
            'new_password.min' => 'Mật khẩu chỉ nằm trong khoảng 6 đến 32 ký tự',
            'new_password.max' => 'Mật khẩu chỉ nằm trong khoảng 6 đến 32 ký tự',
            'password_confirmation.same' => "Mật khẩu mới nhập lại không khớp",
            'new_password.different' => 'Mật khẩu mới không được giống mật khẩu cũ'
        ]);
        $user->password = bcrypt($request->new_password);
        $user->save();
        return response()->json("Đổi mật khẩu thành công!");
    }

    function getFavPost(Request $request)
    {
        $user = $request->user();
        $favourites = $user->favourites->where('status', 1);
        return response()->json([
            'fav_post' => PostResource::collection($favourites),
        ]);
    }

    /**
     * Ghi nhận yêu thích bài đăng
     *
     * @param Request $request
     * @param $id_post
     * @return \Illuminate\Http\JsonResponse
     */
    function postAddFav(Request $request, $id_post)
    {
        $user = $request->user();
        $post = Post::find($id_post);
        if ($post->status != 1) {
            return response()->json([
                'message' => "Bài đăng chưa được duyệt",
            ]);
        }
        $favourites = $user->favourites;
        $temp = array();
        foreach ($favourites as $favourite) {
            $temp[] = $favourite->id;
        }
        if (!in_array($id_post, $temp)) {
            $user->favourites()->attach($id_post);
            return response()->json("Thêm thành công", 201);
        } else {
            return response()->json("Đã được yêu thích trước đó");
        }
    }

    /**
     * Ghi nhận yêu cầu hủy yêu thích
     *
     * @param Request $request
     * @param $id_post
     * @return \Illuminate\Http\JsonResponse
     */
    function postRemoveFav(Request $request, $id_post)
    {
        $user = $request->user();
        //$post = Post::find($id_post);
        $favourites = $user->favourites;
        $temp = array();
        foreach ($favourites as $favourite) {
            $temp[] = $favourite->id;
        }
        if (in_array($id_post, $temp)) {
            $user->favourites()->detach($id_post);
            return response()->json("Gỡ yêu thích thành công");
        } else return response()->json("Bài viết chưa được yêu thích trước đó");
    }

    /**
     * Ghi nhận comment mới
     *
     * @param Request $request
     * @param $id_post
     * @return \Illuminate\Http\JsonResponse
     */
    function postComment(Request $request, $id_post)
    {
        $post = Post::find($id_post);
        if ($post->status != 1) {
            return response()->json([
                'data' => "Bài đăng chưa được duyệt",
            ]);
        }
        $request->validate([
            'cmt' => 'required|max:10000',
            'rate' => 'required|in:' . implode(',', array(1, 2, 3, 4, 5)),
        ]);
        $user = $request->user();
        $cmt = Comment::where([['id_post', $id_post], ['id_from', $user->id]])->first();
        if (isset($cmt)) {
            return response()->json([
                'data' => "Bạn đã đánh giá bài đăng này rồi!",
            ]);
        }
        $cmt = new Comment();
        $cmt->content = $request->cmt;
        $cmt->rate = $request->rate;
        $cmt->id_from = $user->id;
        $cmt->id_post = $id_post;
        $cmt->status = 0;
        $cmt->save();
        return response()->json(['data' => "Thêm bình luận thành công! Bình luận sẽ được hiển thị khi admin duyệt"], 201);
    }

    /**
     * Ghi nhận báo cáo mới
     *
     * @param Request $request
     * @param $id_post
     * @return \Illuminate\Http\JsonResponse
     */
    function postReport(Request $request, $id_post)
    {
        $post = Post::find($id_post);
        if ($post->status != 1) {
            return response()->json([
                'message' => "Bài đăng chưa được duyệt",
            ]);
        }
        $request->validate([
            'req' => 'required|max:10000',
        ]);
        $user = $request->user();
        $rp = new Report();
        $rp->request = $request->req;
        $rp->id_from = $user->id;
        $rp->id_post = $id_post;
        $rp->status = 0;
        $rp->save();
        return response()->json("Thêm report thành công", 201);
    }

    /**
     * Lấy ra toàn bộ thông báo của người dùng hiện tại
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function getNoti(Request $request)
    {
        $noti = Notification::where('id_to', $request->user()->id)->get();
        return response()->json([
            'noti' => $noti,
        ]);
    }

    /**
     * Lấy ra những bài đã đăng của người dùng hiện tại
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    function getPostPosted(Request $request)
    {
        $user = $request->user();
        if (Cache::has('post_posted' . $user->id)) {
            $posts = Cache::get('post_posted' . $user->id);
        } else {
            $posts = $user->posts;
            Cache::put('post_posted' . $user->id, $posts, env('CACHE_TIME', 0));
        }
        return response()->json([
            'post_posted' => PostResource::collection($posts),
        ]);
    }

    /**
     * Ghi nhận xóa bài đăng
     *
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteComment($id)
    {
        $cmt = Comment::find($id);
        $cmt->delete();
        return response()->json(null, 204);
    }
}
