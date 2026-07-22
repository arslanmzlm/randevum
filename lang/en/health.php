<?php

return [

    'clinic_form_title' => 'Anamnesis',
    'patient_form_title' => 'My Health Info',
    'saved' => 'Anamnesis saved.',

    'yes' => 'Yes',
    'no' => 'No',

    'groups' => [
        'general' => 'General',
        'systemic' => 'Systemic / Chronic',
        'allergy' => 'Allergy',
        'women' => 'Women',
        'podiatry' => 'Podiatry',
    ],

    'fields' => [
        'blood_type' => 'Blood Type',
        'height_cm' => 'Height',
        'weight_kg' => 'Weight',
        'smoking' => 'Smoking',
        'alcohol' => 'Alcohol',
        'diabetes' => 'Diabetes',
        'hypertension' => 'Hypertension',
        'cardiovascular' => 'Cardiovascular Disease',
        'blood_thinners' => 'Blood Thinner Use',
        'regular_medications' => 'Regular Medications',
        'other_chronic' => 'Other Chronic Conditions',
        'allergies' => 'Known Allergies',
        'pregnancy' => 'Pregnancy / Breastfeeding',
        'foot_surgery_history' => 'Foot Surgery / Injury History',
        'diabetic_foot_history' => 'Diabetic Foot History',
        'current_foot_complaint' => 'Current Foot Complaint',
    ],

    'options' => [
        'blood_type' => [
            'A+' => 'A+',
            'A-' => 'A-',
            'B+' => 'B+',
            'B-' => 'B-',
            'AB+' => 'AB+',
            'AB-' => 'AB-',
            '0+' => 'O+',
            '0-' => 'O-',
        ],
        'smoking' => [
            'none' => 'Non-smoker',
            'former' => 'Former smoker',
            'active' => 'Smoker',
        ],
        'alcohol' => [
            'none' => 'Never',
            'occasional' => 'Occasional',
            'regular' => 'Regular',
        ],
        'diabetes' => [
            'type1' => 'Type 1',
            'type2' => 'Type 2',
        ],
        'pregnancy' => [
            'pregnant' => 'Pregnant',
            'breastfeeding' => 'Breastfeeding',
        ],
    ],

    'units' => [
        'cm' => 'cm',
        'kg' => 'kg',
    ],

    'errors' => [
        'vertical_mismatch' => "The clinic's vertical is not compatible with this anamnesis type.",
    ],

    'pdf' => [
        'title' => 'Anamnesis Form',
        'date' => 'Date',
        'patient' => 'Patient',
        'empty' => 'No anamnesis record has been filled in yet.',
    ],

];
