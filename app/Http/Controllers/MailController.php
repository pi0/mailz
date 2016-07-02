<?php

namespace App\Http\Controllers;

use App\Http\Requests;
use App\User;
use Auth;
use Hash;
use Illuminate\Http\Request;
use App\Mail;

class MailController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
    }

    public function inbox(Request $request, $tab = 'inbox')
    {
        if ($request->ajax()) {

            $per_page = intval($request->get('per_page'), 10);

            switch ($tab) {
                case 'inbox':
                    $mails = Auth::user()->inbox();
                    break;
                case 'sent':
                    $mails = Auth::user()->sent();
                    break;
                case 'trash':
                    $mails = Auth::user()->inbox()->onlyTrashed();
                    break;
                default:
                    $mails = Auth::user()->inbox()
                        ->whereRaw(['tags' => [
                            '$elemMatch' => ['$eq' => $tab]
                        ]]);
                    break;
            }

            // Sort
            $sortBy = $request->get('sort', 'date');

            if ($sortBy == 'date') {
                $sortBy = 'created_at';
            }

            $mails = $mails->orderBy($sortBy, ($sortBy == 'created_at') ? 'desc' : 'asc');

            $mail = $mails->paginate($per_page);

            return $mails->get();
        }


        return view('inbox', [
            'tab' => $tab,
        ]);
    }

    public function read($mail_id)
    {
        $mail = Mail::withTrashed()->where('_id', $mail_id)->firstOrFail();

        if ($mail->from_id != Auth::user()->id) {
            $mail->read = true;
            $mail->save();
        }

        return view('read', [
            'mail' => $mail
        ]);
    }

    public function delete(Mail $mail)
    {
        $mail->delete();
        return redirect('/inbox');
    }

    public function label(Mail $mail, $as)
    {
        if ($mail->hasTag($as))
            $mail->pull('tags', $as);
        else
            $mail->push('tags', $as, true);

        return redirect('/read/' . $mail->id);
    }

    public function profile()
    {
        return view('profile');

    }

    public function profilePost(Request $request)
    {

        $u = Auth::user();

        if ($request->has('name'))
            $u->name = $request->get('name');

        if ($request->has('password'))
            $u->password = Hash::make($request->get('password'));

        if ($request->hasFile('avatar')) {
            $request->file('avatar')->move('upload/avatar', $u->id . '.png');
        }

        if ($request->hasFile('background')) {
            $request->file('background')->move('upload/background', $u->id . '.png');
        }

        $u->save();

        return redirect('/profile')->with(['message' => 'Profile Saved!']);

    }

    public function contacts()
    {

        // All Users
        $b = @Auth::user()->contact_ids;
        if ($b == null)
            $b = [];
        $b[] = Auth::user()->id;
        $all_users = User::whereNotIn('_id', $b)->get();

        // My Contacts
        $contacts = Auth::user()->contacts;

        return view('contacts', [

            'all_users' => $all_users,
            'contacts' => $contacts,

        ]);
    }


    public function add(User $user)
    {
        Auth::user()->contacts()->attach($user);
        $user->contacts()->attach(Auth::user());

        // Send notify to user
        $mail = new Mail();

        $mail->subject = 'Hello ' . $user->name;
        $mail->text = 'I added you to my contacts list. please add me if you want :)<br>' . Auth::user()->name . '<br>'
            . Auth::user()->email;

        $mail->from_id = Auth::user()->id;
        $mail->to_id = $user->id;

        $mail->save();

        return redirect('/contacts');
    }


    public function reject(User $user)
    {
        Auth::user()->contacts()->detach($user->id);
        $user->contacts()->detach(Auth::user()->id);

        return redirect('/contacts')->with([
            'message' => $user->email . ' Has been rejected!'
        ]);
    }

    public function block(User $user)
    {
        // Block/Unblock
        if (!Auth::user()->hasBlocked($user)) {
            $this->reject($user);
            Auth::user()->push('blocks', $user->id, true);
            $action = 'blocked';
        } else {
            Auth::user()->pull('blocks', $user->id);
            $action = 'unblocked';
        }

        return redirect('/contacts')->with([
            'message' => $user->email . ' Has been ' . $action
        ]);
    }

    public function compose(Request $request)
    {
        $to_email = $request->get('to');
        $subject = $request->get('subject');
        $text = $request->get('text');

        $from = Auth::user();
        $to = User::where('email', $to_email)->first();

        if ($to == null) {
            return redirect('/inbox')->with([
                'message' => 'Mail Not Found'
            ]);
        }

        if ($to->hasBlocked($from)) {
            return redirect('/inbox')->with([
                'message' => 'This user has blocked you!'
            ]);
        }

        if (!in_array($to->id, Auth::user()->contact_ids)) {
            return redirect('/inbox')->with([
                'message' => 'Contact is not in your contacts!!!'
            ]);
        }

        $mail = new Mail();

        $mail->subject = $subject;
        $mail->text = $text;

        $mail->from_id = $from->id;
        $mail->to_id = $to->id;

        // Spam Detection
        if (Auth::user()->sent()->count() > 5 &&
            (Auth::user()->sent()->count() / Auth::user()->inbox()->count()) > 2
        ) {
            $mail->spam = true; // Mark as spam
        }

        $mail->save();

        // Attachments
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $d = 'upload/attachment/' . $mail->id;
            mkdir($d);
            $n = $file->getClientOriginalName();
            $file->move($d, $n);
            $mail->push('attachments', $n);
        }


        return redirect()->back();
    }


}
