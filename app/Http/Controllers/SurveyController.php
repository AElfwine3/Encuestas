<?php

namespace App\Http\Controllers;

use App\Enums\QuestionType;
use App\Models\Survey;
use App\Http\Requests\StoreSurveyRequest;
use App\Http\Requests\UpdateSurveyRequest;
use Illuminate\Http\Request;
use App\Http\Resources\SurveyResource;
use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Arr;

class SurveyController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $user = $request->user();
        return SurveyResource::collection(Survey::where('user_id', $user->id)->paginate(5));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreSurveyRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreSurveyRequest $request)
    {
        $data = $request->validated();
        if (isset($data['image'])){
            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;
        }
        $survey = Survey::create($data);
        foreach ($data['questions'] as $question) {
            $question['survey_id'] = $survey->id;
            $this->createQuestion($question);
        }
        return new SurveyResource($survey);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function show(Survey $survey, Request $request)
    {
        $user = $request->user();
        if ($user->id !== $survey->user_id){
            return abort(403, 'Accion no autorizada.');
        }
        return new SurveyResource($survey);
    }
    public function showForGuest(Survey $survey)
    {
        return new SurveyResource($survey);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateSurveyRequest  $request
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateSurveyRequest $request, Survey $survey)
    {
        $data = $request->validated();
        if (isset($data['image'])){
            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;
            if ($survey->image){
                $absolutePath = public_path($survey->image);
                File::delete($absolutePath);
            }
        }
        $survey->update($data);

        //Obtener id como array de preguntas existentes
        $existingIds = $survey->questions()->pluck('id')->toArray();
        //Obtener id como array de preguntas nuevas
        $newIds = Arr::pluck($data['questions'], 'id');
        //Encontrar preguntas a borrar
        $toDelete = array_diff($existingIds, $newIds);
        //Encontrar preguntas a agregar
        $toAdd = array_diff($newIds, $existingIds);
        //Borrar preguntas
        SurveyQuestion::destroy($toDelete);
        //Crear nuevas preguntas
        foreach ($data['questions'] as $question) {
            if (in_array($question['id'], $toAdd)) {
                $question['survey_id'] = $survey->id;
                $this->createQuestion($question);
            }
        }
        //Modificar preguntas
        $questionMap = collect($data['questions'])->keyBy('id');
        foreach ($survey->questions as $question) {
            if (isset($questionMap[$question->id])) {
                $this->updateQuestion($question, $questionMap[$question->id]);
            }
        }
        
        return new SurveyResource($survey);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Survey  $survey
     * @return \Illuminate\Http\Response
     */
    public function destroy(Survey $survey, Request $request)
    {
        $user = $request->user();
        if ($user->id !== $survey->user_id){
            return abort(403, 'Accion no autorizada.');
        }
        if ($survey->image){
            $absolutePath = public_path($survey->image);
            File::delete($absolutePath);
        }
        $survey->delete();
        return response('Eliminada', 204);
    }

    private function saveImage($image)
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
            $image = substr($image, strpos($image, ',')+1);
            $type = strtolower($type[1]);
            if (!in_array($type, ['jpg', 'jpeg', 'gif', 'png', 'ico'])){
                throw new \Exception('Tipo de imagen desconocido');
            }
            $image = str_replace(' ', '+', $image);
            $image = base64_decode($image);
            if ($image === false) {
                throw new \Exception('base64_decode falló');
            }
        } else {
            throw new \Exception('URI desconocida');
        }
        $dir = 'images/';
        $file = Str::random() . '.' . $type;
        $absolutePath = public_path($dir);
        $relativePath = $dir . $file;
        if (!File::exists($absolutePath)){
            File::makeDirectory($absolutePath, 0755, true);
        }
        file_put_contents($relativePath, $image);
        return $relativePath;
    }

    private function createQuestion($data){
        if (is_array($data['data'])) {
            $data['data'] = json_encode($data['data']);
        }
        $validator = Validator::make($data, [
            'question' => 'required|string',
            'type' => ['required', new Enum(QuestionType::class)],
            'description' => 'nullable|string',
            'data' => 'present',
            'survey_id' => 'exists:App\Models\Survey,id'
        ]);

        return SurveyQuestion::create($validator->validated());
    }

    private function updateQuestion(SurveyQuestion $question, $data)
    {
        if (is_array($data['data'])) {
            $data['data'] = json_encode($data['data']);
        }
        $validator = Validator::make($data, [
            'id' => 'exists:App\Models\SurveyQuestion,id',
            'question' => 'required|string',
            'type' => ['required', new Enum(QuestionType::class)],
            'description' => 'nullable|string',
            'data' => 'present',
        ]);
        return $question->update($validator->validated());
    }
}
