<template>
  <div class="animated fadeIn">
	<b-row>

	</b-row>
	<b-row>
		<b-col cols="4">
		<b-card>
			<b-card-text><h5>Campaigns</h5></b-card-text>
			<b-card-text v-for="(row, row_idx) in list_data" :key="row.id">
				<i class="fa fa-edit" v-if="selected_record.id == row.id"></i>
				<a @click.preventDefault="handleSelect" :id="row_idx">{{row.brand_name}}&nbsp;-&nbsp;{{row.type}}&nbsp;-&nbsp;{{row.offer_name}}</a>
			</b-card-text>
			<b-card-text v-if="list_length == 0">No records found</b-card-text>
		</b-card>
		</b-col>
		<b-col cols="8">
			<b-card>
			<b-card-text><h5>View, Create or Edit Campaigns</h5>To create a campaign, just add the details or click Clear Form. To update an existing campaign, please select from the list on the left.</b-card-text>
			<b-card-text>
        <ValidationObserver v-slot="{ handleSubmit,reset}">
				<b-form-group
					id="fieldset-name"
					label-cols-sm="4"
					label-cols-lg="3"
					description="The label of the Campaign"
					label="Campaign Name"
					label-for="name"
					>
          <validation-provider rules="required" v-slot="{ errors }">
            <span style="font-size:10px;color:red;font-style: italic;">{{ errors[0] }}</span>
            <b-form-input id="name" v-model="selected_record.name"></b-form-input>
          </validation-provider>

				</b-form-group>
				<b-form-group
					id="fieldset-client"
					label-cols-sm="4"
					label-cols-lg="3"
					description="To which Brand does this Campaign apply?"
					label="Brand"
					label-for="brand_id"
					>
					<b-form-select v-model="selected_record.brand_id" :options="brand_list"></b-form-select>
				</b-form-group>
        <b-form-group
					id="fieldset-client"
					label-cols-sm="4"
					label-cols-lg="3"
					description="Select an Offer for this Campaign"
					label="Offer"
					label-for="offer"
					>
					<b-form-select v-model="selected_record.offer_id" :options="offer_list"></b-form-select>
				</b-form-group>
        <b-form-group
					id="fieldset-client"
					label-cols-sm="4"
					label-cols-lg="3"
					description="To which Type does this Campaign apply?"
					label="Type"
					label-for="type"
					>
					<b-form-select v-model="selected_record.type" :options="type_list"></b-form-select>
				</b-form-group>
        <b-form-group
					id="fieldset-client"
					label-cols-sm="4"
					label-cols-lg="3"
					description="Select a commission rate for this Campaign"
					label="Commission"
					label-for="commission"
					>
					<b-form-select v-model="selected_record.commission" :options="commission_opts"></b-form-select>
				</b-form-group>
				<b-form-group
					id="fieldset-credential"
					label-cols-sm="4"
					label-cols-lg="3"
					description="Select a Credential for this Campaign"
					label="Credential"
					label-for="offer"
					>
					<b-form-select v-model="selected_record.credential_type" :options="credential_types_list"></b-form-select>
					<b-form-select v-model="selected_record.credential_id" :options="credential_list | credential_list_filter(selected_record.credential_type, selected_record.credential_id, selected_record.id)" :disabled="!selected_record.credential_type"></b-form-select>
				</b-form-group>
				<b-form-group
					id="fieldset-description"
					label-cols-sm="4"
					label-cols-lg="3"
					description=""
					label="Description"
					label-for="description"
					>
					<b-form-textarea placeholder="Please enter any notes or description here ..." id="description" v-model="selected_record.description"></b-form-textarea>
				</b-form-group>
        <b-button type="submit" variant="primary" v-if="selected_record.id" @click.preventDefault="handleSubmit(saveForm)">Update</b-button>
        <b-button type="submit" variant="primary" v-else @click.preventDefault="handleSubmit(saveForm)">Create</b-button>
        <b-button type="submit" variant="success" @click.preventDefault="reset(clearForm)" >Refresh Form</b-button>
      </ValidationObserver>
      <b-button type="submit" variant="secondary" @click.preventDefault="clearForm" >Clear Form</b-button>
      </ValidationObserver>
        <DeleteButtonComponent :disabled="selected_record.id"  @deleteConfirmed="deleteConfirmed" @deleteDeclined="deleteDeclined" :name="this.pageName"></DeleteButtonComponent>
			</b-card-text>
		</b-card>
		</b-col>
	</b-row>
  </div>
</template>

<script>
import moment from 'moment'
import DeleteButtonComponent from '../components/buttons/DeleteButtonComponent.vue'
import { ValidationProvider, ValidationObserver, extend } from 'vee-validate';
import { required } from 'vee-validate/dist/rules';
var qs = require('qs');

extend('required', {
  ...required,
  message: 'This field is required'
});

export default {
  name: 'IRMBCampaigns',
  components: {
    DeleteButtonComponent,ValidationProvider,ValidationObserver
  },
  mounted() {
	  this.clearForm()
	  this.getList()
	  this.getBrandOptionList()
		this.getOfferOptionList()
		this.getCredentialList()
	},
	computed: {
		list_length: function () {
			return this.list_data.length
		},
		brand_id: function () {
			return this.selected_record.brand_id
		},
		selected_brand: function() {
			let $this = this
			var a = this.brand_list.filter(function(o) {
				return o.value === $this.brand_id
			})

			return a.length == 1 ? a[0] : {}
		},
	},
  filters: {
		credential_list_filter: function (all_credentials, credential_type, selected_id, campaign_id) {
			return all_credentials.filter(function (v, i) {
				return (credential_type ? v.class == credential_type : false) && (!v.campaign_id || selected_id == v.value)
			})
		}
  },
  watch: {
		brand_id: function(v) {
			this.selected_record.commission = !this.selected_record.commission && this.selected_brand.hasOwnProperty('commission') ? this.selected_brand.commission : this.selected_record.commission;
		}
  },
  data: () => {
    return {
    pageName:"Campaign",
		list_data: [],
		selected_record: [],
		brand_list: [],
		offer_list: [],
		credential_list: [],
		credential_types_list: [
			{value: null, text: ' -- None -- '},
			{value: 'IRLApp\\Models\\RefersionCredential', text:"Refersion"}
		],
    type_list: [
      {value:'Influencer', text:'Influencer'},
      {value:'Podcast', text:'Podcast'},
    ],
    commission_opts:[
      {value:'12.5000', text:'12.50 %'},
      {value:'14.0000', text:'14.00 %'},
      {value:'15.0000', text:'15.00 %'},
    ],
	}
  },
  methods: {
		handleSelect(e) {
			this.selected_record = this.list_data[e.srcElement.id]
		},
		clearForm(e) {
			this.selected_record = {
				id: '',
				brand_id: '',
				name: '',
				description: '',
				type: '',
				commission:'',
				offer_id:'',
				credential_type: null,
			}
		},
		saveForm( e ) {
			this.$http.post('/campaign' + (this.selected_record.id ? '/'+this.selected_record.id : ''), this.selected_record).then((r) => {
				this.getList()
				this.getCredentialList()
				this.clearForm()
			})
		},
		getList() {
			this.$http.get('/campaign').then((r) => {
				this.list_data = r.data
			})
		},
		getBrandOptionList() {
			this.$http.get('/brand').then((r) => {
				this.brand_list = r.data.map(function(v, i, a) {
					return {value:v.id, text:v.client_name + ' - ' + v.name, commission:v.commission}
				});
			})
		},
		getOfferOptionList() {
			this.$http.get('/offer').then((r) => {
				this.offer_list = r.data.map(function(v, i, a) {
					return {value:v.id, text:v.brand_name+"-"+v.name}
				});
			})
		},
    deleteConfirmed: function() {
      this.isLoading = true;
      this.$http.get('/campaign/'+this.selected_record.id+'/delete').then((r) => {
        this.getList()
        this.clearForm()
      });
    },
    deleteDeclined: function() {
      console.log("Delete Declined");
    },
		getCredentialList() {
			this.$http.get('/vendor/credentials').then((r) => {
				this.credential_list = r.data.map(function(v, i, a) {
					return {value: v.id, text: v.public_key, campaign_id:v.campaign_id, class:v.class }
				})
			})
		},
		deleteRecord: function() {

			this.$bvModal.msgBoxConfirm('Please confirm that you want to delete this Campaign.', {
				title: 'Please Confirm',
				size: 'sm',
				buttonSize: 'sm',
				okVariant: 'danger',
				okTitle: 'YES',
				cancelTitle: 'NO',
				footerClass: 'p-2',
				hideHeaderClose: false,
				centered: true
			}).then(value => {
          if (value === true) {
						this.isLoading = true;
						this.$http.get('/campaign/'+this.selected_record.id+'/delete').then((r) => {
							this.getList()
							this.clearForm()
						})
					} else {

					}
			}).catch(err => {

			})
		},
    deleteConfirmed: function() {
      this.isLoading = true;
      this.$http.get('/campaign/'+this.selected_record.id+'/delete').then((r) => {
        this.getList()
        this.clearForm()
      });
    },
    deleteDeclined: function() {
      console.log("Delete Declined");
    },
  },
}
</script>

<style scoped>
  .btn.disabled {
    cursor: auto;
  }
	.horizontal-scroll {
		overflow-x: scroll;
	}

	.row-eq-height {
		display: -webkit-box;
		display: -webkit-flex;
		display: -ms-flexbox;
		display:         flex;
	}

	.collapsed > .when-opened,
		:not(.collapsed) > .when-closed {
		display: none;
	}
</style>
